/* =====================================================================
   SARMAD Attendance - device side face gallery cache (stage 5)
   ---------------------------------------------------------------------
   The descriptors a device downloads from /api/v1/device/face-data are
   BIOMETRIC data. They are kept:

     * in memory for matching (fast path), and
     * in IndexedDB so recognition keeps working while the internet is down
       (an offline kiosk must still be able to identify people).

   Rules enforced here:
     * one versioned store per device id - a device can only ever hold its own
       customer's gallery,
     * the store is wiped on `forget`, on deactivation and whenever the server
       reports a different face_data_version (no stale vector survives),
     * nothing in this file renders, logs or transmits a descriptor; the only
       consumer is the matcher.

   Liveness is not implemented - there is nothing here about it either.
   ===================================================================== */

(function (window) {
    'use strict';

    var DB_NAME = 'sarmad-attendance-face';
    var DB_VERSION = 1;
    var STORE = 'gallery';

    /** In-memory copy: the matcher never waits for IndexedDB. */
    var memory = {
        version: -1,
        model_version: '',
        employees: [],
        synced_at: null
    };

    function supported() {
        return typeof window.indexedDB !== 'undefined';
    }

    function open() {
        return new Promise(function (resolve, reject) {
            if (!supported()) {
                reject(new Error('indexeddb_unsupported'));

                return;
            }

            var request = window.indexedDB.open(DB_NAME, DB_VERSION);

            request.onupgradeneeded = function () {
                var db = request.result;

                if (!db.objectStoreNames.contains(STORE)) {
                    db.createObjectStore(STORE);
                }
            };

            request.onsuccess = function () { resolve(request.result); };
            request.onerror = function () { reject(request.error); };
        });
    }

    function keyFor(deviceId, suffix) {
        return 'device:' + String(deviceId || 'unknown') + ':' + suffix;
    }

    /**
     * @param {number} deviceId
     * @returns {Promise<{version:number,model_version:string,employees:Array,device_id:number}|null>}
     */
    function read(deviceId) {
        return open().then(function (db) {
            return new Promise(function (resolve) {
                var tx = db.transaction(STORE, 'readonly');
                var request = tx.objectStore(STORE).get(keyFor(deviceId, 'gallery'));

                request.onsuccess = function () {
                    var value = request.result;

                    if (value && value.device_id === deviceId) {
                        memory = value;

                        resolve(value);
                    } else {
                        resolve(null);
                    }
                };

                request.onerror = function () { resolve(null); };
            });
        })['catch'](function () {
            return null;
        });
    }

    /**
     * Replace the cached gallery (never appended to: a reset must really
     * remove the old vectors).
     */
    function write(deviceId, dataset) {
        var value = {
            device_id: deviceId,
            version: parseInt(dataset.version, 10) || 0,
            model_version: dataset.model_version || '',
            threshold: dataset.threshold || null,
            ambiguity_margin: dataset.ambiguity_margin || null,
            samples_required: dataset.samples_required || 3,
            employees: dataset.employees || [],
            synced_at: new Date().toISOString()
        };

        memory = value;

        return open().then(function (db) {
            return new Promise(function (resolve) {
                var tx = db.transaction(STORE, 'readwrite');

                tx.objectStore(STORE).put(value, keyFor(deviceId, 'gallery'));
                tx.oncomplete = function () { resolve(true); };
                tx.onerror = function () { resolve(false); };
                tx.onabort = function () { resolve(false); };
            });
        })['catch'](function () {
            return false;
        });
    }

    function cached(deviceId) {
        if (memory && memory.device_id === deviceId && memory.employees && memory.employees.length) {
            return memory;
        }

        return null;
    }

    function clear(deviceId) {
        memory = { version: -1, model_version: '', employees: [], synced_at: null };

        if (!supported()) {
            return Promise.resolve(true);
        }

        return open().then(function (db) {
            return new Promise(function (resolve) {
                var tx = db.transaction(STORE, 'readwrite');
                var store = tx.objectStore(STORE);

                store.delete(keyFor(deviceId, 'gallery'));
                tx.oncomplete = function () { resolve(true); };
                tx.onerror = function () { resolve(false); };
            });
        })['catch'](function () {
            return false;
        });
    }

    /** Emergency wipe (used when the device is revoked / forget is pressed). */
    function clearAll() {
        memory = { version: -1, model_version: '', employees: [], synced_at: null };

        if (!supported()) {
            return Promise.resolve(true);
        }

        return open().then(function (db) {
            return new Promise(function (resolve) {
                var tx = db.transaction(STORE, 'readwrite');

                tx.objectStore(STORE).clear();
                tx.oncomplete = function () { resolve(true); };
                tx.onerror = function () { resolve(false); };
            });
        })['catch'](function () {
            return false;
        });
    }

    function size() {
        return (memory.employees || []).reduce(function (total, employee) {
            return total + (employee.samples ? employee.samples.length : 0);
        }, 0);
    }

    window.SarmadFaceStore = {
        supported: supported,
        read: read,
        write: write,
        cached: cached,
        clear: clear,
        clearAll: clearAll,
        size: size,
        memory: function () {
            return {
                version: memory.version,
                model_version: memory.model_version,
                employees: (memory.employees || []).length,
                samples: size(),
                synced_at: memory.synced_at
            };
        }
    };
})(window);
