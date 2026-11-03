// Run: npm install --prefix tests && node tests/kiosk_offline_test.js
// The engine and API are fakes; device.js, queue, and IndexedDB store are real.
const { JSDOM } = require("jsdom");
const { indexedDB } = require("fake-indexeddb");
const fs = require("fs");
const assert = require("assert");
const path = require("path").resolve(__dirname, "..") + "/";
const src = (f) => fs.readFileSync(path + f, "utf8");
const sleep = () => new Promise((r) => setTimeout(r, 25));
const storage = {};
let online = true;
let networkAvailable = true;
let serverError = null;
const calls = [];
let detectMode = "matched";
const config = {
  device: { id: 7, status: "active", name: "Test" },
  customer: { name: "Test" },
  settings: { show_clock: true, success_sound: false },
  features: { attendance: true },
  face: { version: 1, model_version: "v1" },
};
const gallery = {
  version: 1,
  model_version: "v1",
  employees: [
    {
      employee_id: 42,
      first_name: "Sara",
      last_name: "Test",
      samples: [1, 2, 3],
    },
  ],
};
let intervals, dom, win;
async function boot() {
  const view = src("views/device/kiosk.php").replace(/<\?php[\s\S]*?\?>/g, "");
  dom = new JSDOM(
    `<div id="kiosk" data-api="/api/v1/device" data-base="/" data-sw="sw.js" data-offline-shell="0"> <div id="kiosk-boot"></div>${view}</div>`,
    { url: "https://example.test/device", runScripts: "outside-only" },
  );
  win = dom.window;
  intervals = new Map();
  let n = 0;
  Object.defineProperty(win, "indexedDB", { value: indexedDB });
  Object.defineProperty(win.navigator, "onLine", { get: () => online });
  win.navigator.mediaDevices = {
    getUserMedia: async () => ({ getTracks: () => [{ stop() {} }] }),
  };
  const video = win.document.getElementById("k-camera-video");
  Object.defineProperty(video, "readyState", { value: 4 });
  Object.defineProperty(video, "videoWidth", { value: 640 });
  video.play = () => Promise.resolve();
  win.setInterval = (fn, ms) => {
    const id = ++n;
    intervals.set(id, { fn, ms });
    return id;
  };
  win.clearInterval = (id) => intervals.delete(id);
  win.setTimeout = () => ++n;
  win.clearTimeout = () => {};
  for (const [k, v] of Object.entries(storage)) win.localStorage.setItem(k, v);
  let ready = false;
  win.SarmadFace = {
    configure() {},
    isReady: () => ready,
    loadModels: async () => {
      ready = true;
      return { ok: true };
    },
    status: () => ({ loaded: ready }),
    detectFaces: async () => [
      { box: { x: 1, width: 10 }, frame: { width: 100 } },
    ],
    recognize: async () => ({ ok: true, embedding: [1] }),
    match: () =>
      detectMode === "matched"
        ? {
            decision: "matched",
            best: {
              employee_id: 42,
              sample_index: 1,
              distance: 0.2,
              similarity: 0.9,
            },
          }
        : { decision: "unknown" },
  };
  win.fetch = async (url, opts) => {
    calls.push({ url, opts });
    if (!online || !networkAvailable) throw Error("offline");
    if (serverError && url.endsWith("/config"))
      return {
        status: 403,
        json: async () => ({ success: false, code: serverError }),
      };
    let data;
    if (url.endsWith("/config")) data = config;
    else if (url.includes("/face-data")) data = gallery;
    else if (url.endsWith("/heartbeat"))
      data = { device: { last_seen_at: "now" }, face: { changed: false } };
    else if (url.endsWith("/attendance/sync")) {
      const body = JSON.parse(opts.body);
      data = {
        records: body.events.map((x) => ({
          local_event_id: x.local_event_id,
          status: "ACCEPTED",
        })),
      };
    } else data = {};
    return { status: 200, json: async () => ({ success: true, data }) };
  };
  win.eval(src("assets/js/device-queue.js"));
  win.eval(src("assets/js/face/face-store.js"));
  win.eval(src("assets/js/device.js"));
  await sleep();
  await sleep();
}
function persist() {
  for (let i = 0; i < win.localStorage.length; i++) {
    let k = win.localStorage.key(i);
    storage[k] = win.localStorage.getItem(k);
  }
  dom.window.close();
}
async function punch(id) {
  win.document.getElementById(id).click();
  await sleep();
  let task = [...intervals.values()].find((x) => x.ms === 350);
  assert(task, "face loop");
  task.fn();
  await sleep();
  task.fn();
  await sleep();
  await sleep();
}
(async () => {
  storage["sarmad.device.credential"] = JSON.stringify({
    token: "device-token-123",
    device_id: 7,
  });
  await boot();
  assert.equal(
    win.document.getElementById("kiosk").getAttribute("data-state"),
    "home",
  );
  assert(
    JSON.parse(win.localStorage.getItem("sarmad.device.credential_config"))
      .device.id === 7,
  );
  assert.equal(win.SarmadFaceStore.memory().employees, 1);
  persist();
  online = false;
  await boot();
  assert.equal(
    win.document.getElementById("kiosk").getAttribute("data-state"),
    "home",
  );
  assert.equal(win.document.getElementById("k-action-entry").disabled, false);
  assert(win.document.getElementById("k-clock-time").textContent.includes(":"));
  await punch("k-action-entry");
  assert.equal(
    win.document.getElementById("kiosk").getAttribute("data-state"),
    "success",
  );
  assert.equal(
    win.document.getElementById("k-success-title").textContent,
    "ورود در صف ارسال ثبت شد",
  );
  assert(
    win.document
      .getElementById("k-success-body")
      .textContent.includes("Sara Test"),
  );
  assert.equal(
    win.document.getElementById("k-queue-count").textContent,
    "در صف ارسال: 1",
  );
  assert.equal(
    JSON.parse(win.localStorage.getItem("sarmad.device.queue"))[0].event_type,
    "entry",
  );
  win.document.getElementById("kiosk-success").click();
  await punch("k-action-exit");
  assert.equal(
    JSON.parse(win.localStorage.getItem("sarmad.device.queue"))[1].event_type,
    "exit",
  );
  win.document.getElementById("kiosk-success").click();
  detectMode = "unknown";
  await punch("k-action-entry");
  assert.equal(
    win.document.getElementById("kiosk").getAttribute("data-state"),
    "error:unknown_face",
  );
  assert.equal(
    JSON.parse(win.localStorage.getItem("sarmad.device.queue")).length,
    2,
  );
  detectMode = "matched";
  persist();
  await boot();
  assert.equal(
    win.document.getElementById("k-queue-count").textContent,
    "در صف ارسال: 2",
  );
  assert.equal(
    JSON.parse(win.localStorage.getItem("sarmad.device.queue")).length,
    2,
  );
  online = true;
  win.dispatchEvent(new win.Event("online"));
  await sleep();
  await sleep();
  assert.equal(
    JSON.parse(win.localStorage.getItem("sarmad.device.queue")).length,
    0,
  );
  assert(calls.some((x) => x.url.endsWith("/attendance/sync")));
  persist();
  // A network failure, even with navigator.onLine=true, restores the cache.
  networkAvailable = false;
  await boot();
  assert.equal(
    win.document.getElementById("kiosk").getAttribute("data-state"),
    "home",
  );
  assert.equal(win.SARMAD_KIOSK_STATE.online, false);
  // Quota/storage failure must never be reported as queued success.
  const storagePrototype = Object.getPrototypeOf(win.localStorage);
  const originalSet = storagePrototype.setItem;
  storagePrototype.setItem = function (key, value) {
    if (key === "sarmad.device.queue") throw new Error("quota exceeded");
    return originalSet.call(this, key, value);
  };
  await punch("k-action-entry");
  assert.equal(
    win.document.getElementById("kiosk").getAttribute("data-state"),
    "error:storage",
  );
  assert.equal(win.localStorage.getItem("sarmad.device.queue"), "[]");
  storagePrototype.setItem = originalSet;
  persist();
  // Explicit server revocation must not be bypassed by the offline cache.
  networkAvailable = true;
  serverError = "DEVICE_REVOKED";
  await boot();
  assert.equal(
    win.document.getElementById("kiosk").getAttribute("data-state"),
    "blocked:DEVICE_REVOKED",
  );
  assert.equal(
    win.localStorage.getItem("sarmad.device.credential_config"),
    null,
  );
  dom.window.close();

  // Never restore a config belonging to another activation or an absent one.
  serverError = null;
  networkAvailable = false;
  storage["sarmad.device.credential_config"] = JSON.stringify({
    ...config,
    device: { id: 99, status: "active" },
  });
  await boot();
  assert.match(
    win.document.getElementById("kiosk").getAttribute("data-state"),
    /^unactivated:/,
  );
  assert.equal(
    win.localStorage.getItem("sarmad.device.credential_config"),
    null,
  );
  dom.window.close();
  delete storage["sarmad.device.credential"];
  await boot();
  assert.match(
    win.document.getElementById("kiosk").getAttribute("data-state"),
    /^unactivated:/,
  );
  dom.window.close();

  // Queue bounds and storage failures never lose old punches or report success.
  const queueModule = require("../assets/js/device-queue.js");
  let rows = [];
  const queue = queueModule.create(
    {
      read: () => rows.slice(),
      write: (value) => {
        rows = value.slice();
      },
    },
    { max: 2 },
  );
  assert(queue.push({ local_event_id: "1", employee_id: 42 }));
  assert(queue.push({ local_event_id: "2", employee_id: 42 }));
  assert.equal(queue.push({ local_event_id: "3", employee_id: 42 }), null);
  assert.deepEqual(
    rows.map((row) => row.local_event_id),
    ["1", "2"],
  );
  const failed = queueModule.create({ read: () => [], write: () => false });
  assert.equal(failed.push({ local_event_id: "4", employee_id: 42 }), null);
  console.log(
    "Device: online warm, offline reload/entry/exit, persisted queue, reconnect, storage failure and revocation OK",
  );
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
