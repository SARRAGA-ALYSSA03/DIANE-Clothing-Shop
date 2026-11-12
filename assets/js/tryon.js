
(() => {
    'use strict';

    function initializeTryOn() {

        // ==========================================
        // 1. GET HTML ELEMENTS
        // ==========================================

        const camera = document.getElementById('camera');
        const overlay = document.getElementById('clothing-overlay');
        const placeholder = document.getElementById('camera-placeholder');
        const productSelect = document.getElementById('product-select');
        const statusText = document.getElementById('camera-status');

        const cameraButton = document.getElementById('start-camera');
        const oldStopButton = document.getElementById('stop-camera');

        const sizeControl = document.getElementById('size-control');
        const horizontalControl = document.getElementById('horizontal-control');
        const verticalControl = document.getElementById('vertical-control');
        const opacityControl = document.getElementById('opacity-control');

        const sizeValue = document.getElementById('size-value');
        const horizontalValue = document.getElementById('horizontal-value');
        const verticalValue = document.getElementById('vertical-value');
        const opacityValue = document.getElementById('opacity-value');

        const required = [
            camera,
            overlay,
            placeholder,
            productSelect,
            statusText,
            cameraButton,
            sizeControl,
            horizontalControl,
            verticalControl,
            opacityControl,
            sizeValue,
            horizontalValue,
            verticalValue,
            opacityValue
        ];

        if (required.some(element => !element)) {
            console.error('DIANE Try-On: Missing required HTML elements.');
            return;
        }

        // ==========================================
        // 2. CAMERA VARIABLES
        // ==========================================

        let cameraStream = null;
        let currentOverlayPath = '';
        let startingCamera = false;

        // ==========================================
        // 3. INITIAL CAMERA DESIGN
        // ==========================================

        if (oldStopButton) {
            oldStopButton.hidden = true;
            oldStopButton.style.setProperty(
                'display',
                'none',
                'important'
            );
        }

        camera.setAttribute('playsinline', '');
        camera.muted = true;

        camera.style.width = '100%';
        camera.style.height = '100%';
        camera.style.objectFit = 'cover';
        camera.style.objectPosition = 'center';
        camera.style.display = 'block';

        // Make sure the camera stage can contain the overlay.
        const cameraStage = camera.parentElement;

        if (cameraStage) {
            cameraStage.style.position = 'relative';
            cameraStage.style.overflow = 'hidden';
        }

        // Clothing overlay positioning.
        overlay.style.position = 'absolute';
        overlay.style.zIndex = '2';
        overlay.style.objectFit = 'contain';
        overlay.style.pointerEvents = 'none';
        overlay.style.maxWidth = 'none';
        overlay.style.height = 'auto';

        // ==========================================
        // 4. CAMERA BUTTON
        // ==========================================

        function updateCameraButton(isRunning) {

            cameraButton.textContent = isRunning
                ? 'CLOSE CAMERA'
                : 'OPEN CAMERA';

            cameraButton.disabled = false;

            cameraButton.setAttribute(
                'aria-label',
                isRunning ? 'Close camera' : 'Open camera'
            );
        }

        // ==========================================
        // 5. CLOTHING ADJUSTMENT CONTROLS
        // ==========================================

        function updateControls() {

            const size = Number(sizeControl.value);
            const horizontal = Number(horizontalControl.value);
            const vertical = Number(verticalControl.value);
            const opacity = Number(opacityControl.value);

            overlay.style.width = `${size}%`;
            overlay.style.left = `${horizontal}%`;
            overlay.style.top = `${vertical}%`;

            overlay.style.transform = 'translate(-50%, -50%)';
            overlay.style.opacity = String(opacity / 100);

            sizeValue.textContent = `${size}%`;
            horizontalValue.textContent = `${horizontal}%`;
            verticalValue.textContent = `${vertical}%`;
            opacityValue.textContent = `${opacity}%`;
        }

        // ==========================================
        // 6. UPDATE CLOTHING IMAGE
        // ==========================================

        function updateClothing() {

            const option = productSelect.selectedOptions[0];

            if (!option) return;

            const path = (option.dataset.overlay || '').trim();

            // No transparent image available.
            if (!path) {

                currentOverlayPath = '';

                overlay.hidden = true;
                overlay.removeAttribute('src');

                statusText.textContent =
                    'No transparent try-on image is connected to this product yet.';

                updateControls();

                return;
            }

            // Load a different product image.
            if (currentOverlayPath !== path) {

                currentOverlayPath = path;

                overlay.hidden = true;

                overlay.onload = () => {

                    if (currentOverlayPath !== path) return;

                    // Show only when camera is running.
                    overlay.hidden = !cameraStream;

                    updateControls();
                };

                overlay.onerror = () => {

                    if (currentOverlayPath !== path) return;

                    overlay.hidden = true;

                    statusText.textContent =
                        'Unable to load clothing image. Check the image path.';
                };

                overlay.src = path;

            } else if (
                overlay.complete &&
                overlay.naturalWidth > 0
            ) {

                overlay.hidden = !cameraStream;
            }

            updateControls();
        }

        // ==========================================
        // 7. CONTROL EVENT LISTENERS
        // ==========================================

        productSelect.addEventListener(
            'change',
            updateClothing
        );

        [
            sizeControl,
            horizontalControl,
            verticalControl,
            opacityControl
        ].forEach(control => {

            control.addEventListener(
                'input',
                updateControls
            );

        });

        // ==========================================
        // 8. OPEN CAMERA
        // ==========================================

        async function openCamera() {

            if (startingCamera || cameraStream) return;

            if (
                !navigator.mediaDevices ||
                !navigator.mediaDevices.getUserMedia
            ) {

                statusText.textContent =
                    'Camera access requires HTTPS or localhost.';

                return;
            }

            startingCamera = true;

            cameraButton.disabled = true;

            statusText.textContent = 'Opening camera...';

            try {

                const stream =
                    await navigator.mediaDevices.getUserMedia({

                        video: {
                            width: {
                                ideal: 1280
                            },

                            height: {
                                ideal: 720
                            },

                            facingMode: 'user'
                        },

                        audio: false
                    });

                cameraStream = stream;

                camera.srcObject = stream;

                await camera.play();

                // Hide welcome placeholder.
                placeholder.hidden = true;

                // Show clothing if image is ready.
                overlay.hidden = !(
                    overlay.complete &&
                    overlay.naturalWidth > 0
                );

                // Change button.
                updateCameraButton(true);

                statusText.textContent =
                    'Webcam is running.';

                // Listen if webcam stops unexpectedly.
                stream.getVideoTracks().forEach(track => {

                    track.addEventListener('ended', () => {

                        if (cameraStream === stream) {
                            stopCamera();
                        }

                    });

                });

            } catch (error) {

                console.error(
                    'DIANE Camera Error:',
                    error
                );

                stopCamera(false);

                const messages = {

                    NotAllowedError:
                        'Please allow camera permission in your browser.',

                    NotFoundError:
                        'No webcam detected. Check your USB webcam.',

                    NotReadableError:
                        'Your webcam may be in use by another application.',

                    OverconstrainedError:
                        'The requested camera settings are unavailable.',

                    SecurityError:
                        'Camera access requires HTTPS or localhost.'
                };

                statusText.textContent =
                    messages[error.name] ||
                    `Unable to open camera (${error.name || 'Unknown error'}).`;

            } finally {

                startingCamera = false;

                cameraButton.disabled = false;
            }
        }

        // ==========================================
        // 9. CLOSE CAMERA
        // ==========================================

        function stopCamera(showStatus = true) {

            if (cameraStream) {

                cameraStream.getTracks().forEach(track => {
                    track.stop();
                });

                cameraStream = null;
            }

            camera.pause();

            camera.srcObject = null;

            // Hide clothing overlay.
            overlay.hidden = true;

            // Restore welcome placeholder.
            placeholder.hidden = false;

            // Restore button.
            updateCameraButton(false);

            if (showStatus) {

                statusText.textContent =
                    'Camera is off.';
            }
        }

        // ==========================================
        // 10. OPEN / CLOSE CAMERA BUTTON
        // ==========================================

        async function toggleCamera() {

            if (startingCamera) return;

            if (cameraStream) {

                stopCamera();

            } else {

                await openCamera();
            }
        }

        cameraButton.addEventListener(
            'click',
            toggleCamera
        );

        // ==========================================
        // 11. PRODUCT CARD TRY-ON BUTTON
        // ==========================================

        window.openTryOn = function(button) {

            if (
                !button ||
                !button.dataset.productId
            ) return;

            productSelect.value =
                button.dataset.productId;

            updateClothing();

            const section =
                document.getElementById('try-on');

            if (section) {

                section.scrollIntoView({
                    behavior: 'smooth'
                });
            }
        };

        // ==========================================
        // 12. CLEAN UP WHEN LEAVING PAGE
        // ==========================================

        window.addEventListener('pagehide', () => {

            stopCamera(false);

        });

        // ==========================================
        // 13. INITIALIZE EVERYTHING
        // ==========================================

        updateCameraButton(false);

        updateControls();

        updateClothing();

        console.log('DIANE Virtual Try-On initialized.');

    }

    // Wait until HTML is ready.
    if (document.readyState === 'loading') {

        document.addEventListener(
            'DOMContentLoaded',
            initializeTryOn
        );

    } else {

        initializeTryOn();
    }

})();
