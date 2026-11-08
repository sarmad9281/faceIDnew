/**
 * =====================================================================
 * SARMAD Attendance - kiosk offline queue (stage 6/7)
 * =====================================================================
 *
 * The kiosk recognises faces ON THE DEVICE. When it can not reach the server
 * (internet down) the recognised event is stored here and synced later.
 *
 * An item carries exactly the fields the server re-validates (R14):
 *
 *   Queue ID          queue_id
 *   Device ID         device_id           (informational - the server uses the token)
 *   Local Event ID    local_event_id      (unique per device, the dedupe key)
 *   Local Created At  local_created_at    (device clock - NEVER trusted as the
 *                                          attendance time by the server)
 *   Recognition Result recognition { outcome, distance, confidence,
 *                                    sample_index, model_version }
 *   Employee ID       employee_id         (the employee the LOCAL matcher chose)
 *   Event Type        event_type          (entry | exit)
 *
 * It stores no image, no face descriptor and no token copy: the raw camera
 * frame never leaves the device and the queue can not be used as a biometric
 * data store.
 *
 * The module is dependency free and can be loaded both by the kiosk page and
 * by a Node test, so the very code that runs on the device is what the test
 * suite drives.
 *
 * @returns {object} queue API
 */
(function (root, factory) {
    'use strict';

    var api = factory();

    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    }

    if (root) {
        root.SarmadDeviceQueue = api;
    }
}(typeof self !== 'undefined' ? self : (typeof globalThis !== 'undefined' ? globalThis : null), function () {
    'use strict';

    var MAX_ITEMS = 200;
    var DEFAULT_BATCH = 50;

    function clean(value, max) {
        return String(value === null || value === undefined ? '' : value).slice(0, max || 64);
    }

    function numOrNull(value) {
        return typeof value === 'number' && isFinite(value) ? Math.round(value * 10000) / 10000 : null;
    }

    /**
     * Build one queue item. `event` comes from the local recognition result.
     *
     * @param {object} event
     * @returns {object}
     */
    function item(event) {
        event = event || {};

        var localId = clean(event.local_event_id, 64);
        var recognition = event.recognition || {};

        return {
            queue_id: localId,
            local_event_id: localId,
            device_id: parseInt(event.device_id, 10) || 0,
            event_type: (String(event.event_type || '').toLowerCase() === 'exit') ? 'exit' : 'entry',
            employee_id: parseInt(event.employee_id, 10) || 0,
            local_created_at: clean(event.local_created_at, 40),
            request_id: clean(event.request_id, 64),
            recognition: {
                outcome: clean(recognition.outcome, 40) || 'FACE_MATCHED',
                distance: numOrNull(recognition.distance),
                confidence: numOrNull(recognition.confidence),
                sample_index: parseInt(recognition.sample_index, 10) || null,
                model_version: clean(recognition.model_version, 60)
            }
        };
    }

    /**
     * The batch that is POSTed to /api/v1/device/attendance/sync.
     * Optional fields are omitted, never sent as null, so the server sees
     * exactly what the device knows.
     *
     * @param {Array<object>} items
     * @param {number} limit
     * @returns {Array<object>}
     */
    function buildBatch(items, limit) {
        var max = Math.max(1, parseInt(limit, 10) || DEFAULT_BATCH);
        var out = [];

        (items || []).slice(0, max).forEach(function (row) {
            var entry = {
                type: row.event_type,
                local_event_id: row.local_event_id,
                employee_id: row.employee_id,
                occurred_at: row.local_created_at,
                request_id: row.request_id,
                model_version: (row.recognition && row.recognition.model_version) || ''
            };

            if (row.recognition && row.recognition.distance !== null && row.recognition.distance !== undefined) {
                entry.distance = row.recognition.distance;
            }

            if (row.recognition && row.recognition.confidence !== null && row.recognition.confidence !== undefined) {
                entry.confidence = row.recognition.confidence;
            }

            if (row.recognition && row.recognition.sample_index) {
                entry.sample_index = row.recognition.sample_index;
            }

            out.push(entry);
        });

        return out;
    }

    /**
     * Apply the server verdicts to the local queue.
     *
     * ACCEPTED and DUPLICATE items are dropped - the server owns them now.
     * REJECTED and INVALID items stay in the queue, because the server refused
     * them and the device must never silently lose a punch: the manager has to
     * see it and decide.
     *
     * @param {Array<object>} items
     * @param {Array<object>} records  data.records of the sync answer
     * @returns {{kept:Array<object>, dropped:Array<object>, refused:Array<object>, accepted:number, duplicate:number}}
     */
    function applyResults(items, records) {
        var verdicts = {};
        var accepted = 0;
        var duplicate = 0;

        (records || []).forEach(function (record) {
            var key = String(record.local_event_id || '');
            var status = String(record.status || '');

            verdicts[key] = { status: status, code: record.code || null, message: record.message || '' };

            if (status === 'ACCEPTED') {
                accepted++;
            } else if (status === 'DUPLICATE') {
                duplicate++;
            }
        });

        var kept = [];
        var dropped = [];
        var refused = [];

        (items || []).forEach(function (row) {
            var verdict = verdicts[String(row.local_event_id)];

            if (verdict && (verdict.status === 'ACCEPTED' || verdict.status === 'DUPLICATE')) {
                dropped.push(row);

                return;
            }

            if (verdict) {
                refused.push({ item: row, verdict: verdict });
            }

            kept.push(row);
        });

        return { kept: kept, dropped: dropped, refused: refused, accepted: accepted, duplicate: duplicate };
    }

    /**
     * A queue bound to one storage (localStorage on the device, a plain object
     * in a test).
     *
     * @param {{read:function, write:function}} storage
     * @param {{max?:number}} options
     */
    function create(storage, options) {
        var max = (options && options.max) || MAX_ITEMS;
        var listeners = [];

        function read() {
            var items = storage.read();

            return Array.isArray(items) ? items : [];
        }

        function write(items) {
            var list = (items || []).slice(-max);

            storage.write(list);
            listeners.forEach(function (listener) {
                listener(list.length);
            });

            return list;
        }

        return {
            read: read,
            write: write,

            /** Store one recognised event (returns the stored item). */
            push: function (event) {
                var items = read();
                var row = item(event);

                if (!row.local_event_id || !row.employee_id) {
                    return null;
                }

                // The local event id is the device side dedupe key: a double
                // tap can never put the same punch in the queue twice.
                for (var i = 0; i < items.length; i++) {
                    if (items[i].local_event_id === row.local_event_id) {
                        return items[i];
                    }
                }

                items.push(row);
                write(items);

                return row;
            },

            count: function () {
                return read().length;
            },

            buildBatch: function (limit) {
                return buildBatch(read(), limit);
            },

            /** Drop what the server accepted, keep what it refused. */
            applyResults: function (records) {
                var result = applyResults(read(), records);

                write(result.kept);

                return result;
            },

            /** Non sensitive summary for the kiosk screen. */
            summary: function () {
                var items = read();
                var byType = { entry: 0, exit: 0 };

                items.forEach(function (row) {
                    if (byType[row.event_type] !== undefined) {
                        byType[row.event_type]++;
                    }
                });

                return { count: items.length, entry: byType.entry, exit: byType.exit };
            },

            onChange: function (listener) {
                if (typeof listener === 'function') {
                    listeners.push(listener);
                }
            }
        };
    }

    return {
        MAX_ITEMS: MAX_ITEMS,
        DEFAULT_BATCH: DEFAULT_BATCH,
        item: item,
        buildBatch: buildBatch,
        applyResults: applyResults,
        create: create
    };
}));
