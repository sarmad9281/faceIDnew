# Third party assets bundled under `assets/vendor/`

Every file in this folder is served from the same origin as the panel (no CDN,
no external call at runtime) and is listed in `docs/STAGE20.md`.

| File | Package | Version | License | Source |
| --- | --- | --- | --- | --- |
| `face-api-0.22.2.min.js` | face-api.js | 0.22.2 | MIT | https://github.com/justadudewhohacks/face-api.js |
| `tfjs-1.7.4.min.js` | @tensorflow/tfjs-core | 1.7.4 | Apache-2.0 | https://github.com/tensorflow/tfjs |
| `jsQR.min.js` | jsQR | 1.4.0 | Apache-2.0 | https://github.com/cozmo/jsQR |
| `qrcode.min.js` | qrcode-generator | 1.4.4 | MIT | https://github.com/kazuhikoarase/qrcode-generator |

`jsQR` decodes a QR symbol **in the browser, in memory** (an `ImageData`
buffer). The kiosk never uploads or stores that buffer, so a QR punch writes
zero bytes to the server disk.

`qrcode-generator` renders the employee badge as an inline SVG in the manager
panel (no image file is created for a badge either).

## Apache-2.0 notice (jsQR, @tensorflow/tfjs)

Licensed under the Apache License, Version 2.0 (the "License"); you may not use
those files except in compliance with the License. You may obtain a copy of the
License at

    http://www.apache.org/licenses/LICENSE-2.0

Unless required by applicable law or agreed to in writing, software distributed
under the License is distributed on an "AS IS" BASIS, WITHOUT WARRANTIES OR
CONDITIONS OF ANY KIND, either express or implied. See the License for the
specific language governing permissions and limitations under the License.

## MIT notice (qrcode-generator)

Copyright (c) 2009 Kazuhiko Arase

Permission is hereby granted, free of charge, to any person obtaining a copy of
this software and associated documentation files (the "Software"), to deal in
the Software without restriction, including without limitation the rights to
use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of
the Software, and to permit persons to whom the Software is furnished to do so,
subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS
FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR
COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER
IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN
CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
