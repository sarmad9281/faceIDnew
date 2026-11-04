// Run with Node; no server or browser required.
"use strict";
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");
const source = fs.readFileSync(path.join(__dirname, "..", "sw.js"), "utf8");
const layout = fs.readFileSync(path.join(__dirname, "..", "views/layouts/device.php"), "utf8");
// Stage 21 pins every stylesheet to Url::CSS_VERSION (1.2.1, link built by the
// `$css()` helper) and the kiosk scripts to their own explicit versions. The
// version query is what makes a service worker installation (and a browser)
// fetch the NEW file instead of its cached copy - and it must appear exactly
// once, never as the historical `?v=1.0.9&v=1.1.0` double parameter.
const urlSource = fs.readFileSync(path.join(__dirname, '..', 'app/Support/Url.php'), 'utf8');
const viewSource = fs.readFileSync(path.join(__dirname, '..', 'app/Core/View.php'), 'utf8');

assert(
  /public\s+const\s+CSS_VERSION\s*=\s*'1\.2\.1'/.test(urlSource),
  'Url::CSS_VERSION must be 1.2.1',
);
assert(viewSource.includes("'css'") && viewSource.includes('Url::css'), 'views get the css() helper');

const EXPECTED_ASSET_VERSIONS = {
  '/js/device.js': '1.2.0',
  '/js/device-queue.js': '1.1.0',
  '/js/device-qr.js': '1.2.0',
  '/js/face/face-engine.js': '1.1.0',
  '/js/face/face-store.js': '1.1.0',
};
for (const [asset, version] of Object.entries(EXPECTED_ASSET_VERSIONS)) {
  const line = layout.split('\n').find(line => line.includes("$asset('" + asset + "'"));
  assert(line, asset + ' must be loaded by the device layout');
  assert(
    line.includes(", '" + version + "'"),
    asset + ' must pass version ' + version + ' to $asset()',
  );
}
assert(layout.includes("$css('/css/device.css')"), 'device.css must be versioned by Url::css()');
assert(!layout.includes('&v='), 'no view may append a second &v= parameter');
assert(source.includes("'./assets/css/device.css?v=1.2.1'"), 'the shell precaches the exact CSS URL');

async function scenario(scope) {
  const listeners = {};
  const stores = new Map([
    ["other-application", new Map()],
    ["sarmad-kiosk-v6", new Map()],
  ]);
  const resolve = (value) =>
    new URL(typeof value === "string" ? value : value.url, scope).href;
  const self = {
    location: new URL(scope),
    registration: { scope },
    addEventListener(name, fn) {
      listeners[name] = fn;
    },
    skipWaiting: async () => {},
    clients: { claim: async () => {} },
  };
  let online = true;
  let precached = [];
  const networkRequests = [];
  const cacheApi = {
    async open(name) {
      if (!stores.has(name)) stores.set(name, new Map());
      const map = stores.get(name);
      return {
        async addAll(urls) {
          precached = Array.from(urls);
          for (const url of urls) {
            const response = await fetch(url);
            if (!response.ok) throw Error("precache failed");
            map.set(resolve(url), response);
          }
        },
        async match(request, options = {}) {
          const requested = new URL(resolve(request));
          for (const [key, value] of map) {
            const candidate = new URL(key);
            if (
              options.ignoreSearch
                ? candidate.origin === requested.origin &&
                  candidate.pathname === requested.pathname
                : candidate.href === requested.href
            )
              return value;
          }
        },
        async put(request, response) {
          map.set(resolve(request), response);
        },
      };
    },
    async keys() {
      return [...stores.keys()];
    },
    async delete(name) {
      return stores.delete(name);
    },
  };
  class Response {
    constructor(body, options) {
      this.body = body;
      this.status = options.status;
    }
  }
  const fetch = async (request) => {
    if (!online) throw Error("offline");
    networkRequests.push(request);
    return {
      body: typeof request === "string" ? request : "network",
      ok: true,
      clone() {
        return this;
      },
    };
  };
  vm.runInNewContext(source, {
    self,
    caches: cacheApi,
    URL,
    Response,
    fetch,
    Promise,
  });
  // Simulate an existing v9 installation. Old shell/audio must never be
  // reused by v10, while model shards remain available during an upgrade.
  const v9 = await cacheApi.open("sarmad-kiosk-v9");
  for (const clip of ["entry", "exit", "error"]) {
    await v9.put("./assets/audio/" + clip + ".mp3?v=1.0.9", {body: "old-audio-" + clip, ok: true, clone() { return this; }});
  }
  await v9.put("./assets/js/device.js?v=1.0.9", {body: "old-device-script", ok: true, clone() { return this; }});
  await v9.put("./assets/models/face-api-1.0.0/ssd_mobilenetv1_model-shard1", {body: "v9-model", ok: true, clone() { return this; }});
  await v9.put("./assets/models/face-api-1.0.0/tiny_face_detector_model-shard1", {body: "v9-preferred-model", ok: true, clone() { return this; }});
  let pending;
  listeners.install({
    waitUntil(p) {
      pending = p;
    },
  });
  await pending;
  assert(precached.includes("./device"));
  assert(precached.includes("./device/offline"));
  assert(precached.includes("./assets/js/device.js?v=1.2.0"));
  assert(precached.includes("./assets/css/device.css?v=1.2.1"));
  assert(precached.includes("./assets/js/device-queue.js?v=1.1.0"));
  assert(!precached.some((item) => item.includes("/assets/models/")), "models must not block install");
  for (const clip of ['entry', 'exit', 'error']) {
    assert(precached.includes('./assets/audio/' + clip + '.mp3?v=1.1.0'));
    assert(networkRequests.includes('./assets/audio/' + clip + '.mp3?v=1.1.0'), "v12 fetches the new URL during install");
    assert(!networkRequests.includes('./assets/audio/' + clip + '.mp3?v=1.0.9'));
    const file = fs.readFileSync(path.join(__dirname, '..', 'assets', 'audio', clip + '.mp3'));
    assert(file.length > 0 && file.length < 50 * 1024, 'audio must be lightweight');
    assert(file.subarray(0, 3).toString() === 'ID3' || (file[0] === 0xff && (file[1] & 0xe0) === 0xe0), 'valid MP3 header');
  }
  const oldCache = await cacheApi.open("sarmad-kiosk-v7");
  const newerCache = await cacheApi.open("sarmad-kiosk-v8");
  await newerCache.put("./assets/models/face-api-1.0.0/tiny_face_detector_model-shard1", {body: "v8-model", ok: true, clone() { return this; }});
  await newerCache.put("./assets/models/face-api-1.0.0/face_landmark_68_model-shard1", {body: "v8-only-model", ok: true, clone() { return this; }});
  await oldCache.put("./assets/models/face-api-1.0.0/face_recognition_model-shard1", {body: "old-model", ok: true, clone() { return this; }});
  listeners.activate({
    waitUntil(p) {
      pending = p;
    },
  });
  await pending;
  assert(!stores.has("sarmad-kiosk-v6"));
  assert(stores.has("other-application"));
  assert(stores.has("sarmad-kiosk-v7"));
  assert(stores.has("sarmad-kiosk-v8"));
  assert(stores.has("sarmad-kiosk-v9"));
  async function request(
    route,
    mode = "navigate",
    method = "GET",
    origin = new URL(scope).origin,
  ) {
    let response;
    listeners.fetch({
      request: { method, mode, url: new URL(route, origin).href },
      respondWith(p) {
        response = p;
      },
    });
    return response ? await response : null;
  }
  // Before warm-up, a v9-only model is still available offline.
  const base = new URL(scope).pathname;
  online = false;
  assert.equal((await request(base + "assets/models/face-api-1.0.0/ssd_mobilenetv1_model-shard1", "cors")).body, "v9-model");
  assert.equal((await request(base + "assets/models/face-api-1.0.0/face_landmark_68_model-shard1", "cors")).body, "v8-only-model");
  assert.equal((await request(base + "assets/models/face-api-1.0.0/face_recognition_model-shard1", "cors")).body, "old-model");
  for (const clip of ["entry", "exit", "error"]) {
    assert.equal((await request(base + "assets/audio/" + clip + ".mp3?v=1.1.0", "cors")).body, "./assets/audio/" + clip + ".mp3?v=1.1.0");
  }
  assert.equal((await request(base + "assets/js/device.js?v=1.2.0", "cors")).body, "./assets/js/device.js?v=1.2.0");
  online = true;
  // The core installs without invoking the network for any model.
  listeners.message({data: "CACHE_MODELS", waitUntil(p) {pending = p;}});
  await pending;
  assert(stores.get("sarmad-kiosk-v12").has(resolve("./assets/models/face-api-1.0.0/face_recognition_model-shard1")));
  assert.equal(stores.get("sarmad-kiosk-v12").get(resolve("./assets/models/face-api-1.0.0/tiny_face_detector_model-shard1")).body, "v9-preferred-model");
  assert.equal(stores.get("sarmad-kiosk-v12").get(resolve("./assets/models/face-api-1.0.0/ssd_mobilenetv1_model-shard1")).body, "v9-model");
  assert.equal(stores.get("sarmad-kiosk-v12").get(resolve("./assets/models/face-api-1.0.0/face_landmark_68_model-shard1")).body, "v8-only-model");
  assert.equal((await request(base + "device")).body, "network");
  online = false;
  assert.equal((await request(base + "device?x=1")).body, "./device");
  assert.equal((await request(base + "device/")).body, "./device");
  for (const clip of ["entry", "exit", "error"]) assert.equal((await request(base + "assets/audio/" + clip + ".mp3?v=1.1.0", "cors")).body, "./assets/audio/" + clip + ".mp3?v=1.1.0");
  assert.equal(await request(base), null);
  assert.equal(await request(base + "owner"), null);
  assert.equal(await request(base + "devices"), null);
  assert.equal(await request(base + "api/v1/device/config", "cors"), null);
  assert.equal(await request(base + "device", "navigate", "POST"), null);
  assert.equal(
    (await request(base + "assets/js/device.js?v=1.2.0&v=1.2.0", "no-cors"))
      .body,
    "./assets/js/device.js?v=1.2.0",
  );
  assert.equal(
    (
      await request(
        base + "assets/models/face-api-1.0.0/face_recognition_model-shard1",
        "cors",
      )
    ).body,
    "old-model",
  );
  const cache = stores.get("sarmad-kiosk-v12");
  cache.delete(resolve("./device"));
  assert.equal((await request(base + "device")).body, "./device/offline");
  cache.delete(resolve("./device/offline"));
  assert.equal((await request(base + "device")).status, 503);
}
(async () => {
  await scenario("https://example.test/");
  await scenario("https://example.test/attendance/");
  console.log(
    "Service worker: root/subdirectory precache, offline fallback, versioned assets, and scope OK",
  );
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
