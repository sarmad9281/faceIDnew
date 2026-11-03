// Run with Node; no server or browser required.
"use strict";
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");
const source = fs.readFileSync(path.join(__dirname, "..", "sw.js"), "utf8");

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
  const cacheApi = {
    async open(name) {
      if (!stores.has(name)) stores.set(name, new Map());
      const map = stores.get(name);
      return {
        async addAll(urls) {
          precached = Array.from(urls);
          for (const url of urls) map.set(resolve(url), { body: url });
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
  const fetch = async () => {
    if (!online) throw Error("offline");
    return {
      body: "network",
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
  let pending;
  listeners.install({
    waitUntil(p) {
      pending = p;
    },
  });
  await pending;
  assert(precached.includes("./device"));
  assert(precached.includes("./device/offline"));
  for (const name of [
    "tiny_face_detector_model-shard1",
    "ssd_mobilenetv1_model-shard1",
    "ssd_mobilenetv1_model-shard2",
    "face_landmark_68_model-shard1",
    "face_recognition_model-shard1",
    "face_recognition_model-shard2",
  ]) {
    const file = "./assets/models/face-api-1.0.0/" + name;
    assert(precached.includes(file), `missing model ${file}`);
    assert(
      fs.existsSync(path.join(__dirname, "..", file)),
      `missing on disk ${file}`,
    );
  }
  listeners.activate({
    waitUntil(p) {
      pending = p;
    },
  });
  await pending;
  assert(!stores.has("sarmad-kiosk-v6"));
  assert(stores.has("other-application"));
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
  const base = new URL(scope).pathname;
  assert.equal((await request(base + "device")).body, "network");
  online = false;
  assert.equal((await request(base + "device?x=1")).body, "./device");
  assert.equal((await request(base + "device/")).body, "./device");
  assert.equal(await request(base), null);
  assert.equal(await request(base + "owner"), null);
  assert.equal(await request(base + "devices"), null);
  assert.equal(await request(base + "api/v1/device/config", "cors"), null);
  assert.equal(await request(base + "device", "navigate", "POST"), null);
  assert.equal(
    (await request(base + "assets/js/device.js?v=1.0.7&v=1.0.7", "no-cors"))
      .body,
    "./assets/js/device.js",
  );
  assert.equal(
    (
      await request(
        base + "assets/models/face-api-1.0.0/face_recognition_model-shard1",
        "cors",
      )
    ).body,
    "./assets/models/face-api-1.0.0/face_recognition_model-shard1",
  );
  const cache = stores.get("sarmad-kiosk-v7");
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
