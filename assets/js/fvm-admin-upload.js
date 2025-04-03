document.addEventListener("DOMContentLoaded", function () {
    const setupDropzone = (
        formId,
        fileInputId,
        selectFileBtnId,
        uploadButtonId,
        fileNameDisplayId
    ) => {
        const form = document.getElementById(formId);
        // Ensure form exists before proceeding
        if (!form) {
            // console.warn(`Dropzone setup skipped: Form with ID "${formId}" not found.`);
            return;
        }

        const dropzone = form.querySelector(".fvm-dropzone");
        const fileInput = form.querySelector("#" + fileInputId);
        const selectFileBtn = form.querySelector("#" + selectFileBtnId);
        const uploadButton = uploadButtonId
            ? form.querySelector("#" + uploadButtonId)
            : null;
        const fileNameDisplay = form.querySelector("#" + fileNameDisplayId);
        const uploadInstructions = form.querySelectorAll(
            ".fvm-upload-instructions"
        );
        const postUploadInfo = form.querySelector(".post-upload-ui");
        const fileNameContainer = form.querySelector(
            ".fvm-file-name-container"
        );
        const clearFileBtn = form.querySelector(".fvm-clear-file");

        // Ensure all required elements exist
        if (
            !dropzone ||
            !fileInput ||
            !selectFileBtn ||
            !fileNameDisplay ||
            !fileNameContainer ||
            !clearFileBtn
        ) {
            // console.warn(`Dropzone setup skipped for form "${formId}": One or more required elements not found.`);
            return;
        }

        // Drag and drop functionality
        ["dragenter", "dragover", "dragleave", "drop"].forEach((eventName) => {
            dropzone.addEventListener(eventName, preventDefaults, false);
        });

        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }

        ["dragenter", "dragover"].forEach((eventName) => {
            dropzone.addEventListener(
                eventName,
                () => dropzone.classList.add("highlight"),
                false
            );
        });

        ["dragleave", "drop"].forEach((eventName) => {
            dropzone.addEventListener(
                eventName,
                () => dropzone.classList.remove("highlight"),
                false
            );
        });

        dropzone.addEventListener("drop", handleDrop, false);

        function handleDrop(e) {
            const dt = e.dataTransfer;
            const files = dt.files;
            handleFiles(files);
        }

        selectFileBtn.addEventListener("click", () => {
            fileInput.click();
        });

        fileInput.addEventListener("change", () => {
            handleFiles(fileInput.files);
        });

        function handleFiles(files) {
            if (files.length > 0) {
                const fileNames = Array.from(files)
                    .map((file) => file.name)
                    .join(", ");
                fileNameDisplay.textContent =
                    files.length > 1
                        ? `${files.length} files selected`
                        : fileNames;
                fileNameContainer.style.display = "flex";
                selectFileBtn.style.display = "none";
                if (uploadButton) {
                    uploadButton.style.display = "inline-block";
                    uploadButton.value =
                        files.length > 1 ? "Upload Files" : "Upload File";
                }
                // Hide upload instructions and post-upload info
                uploadInstructions.forEach((el) => (el.style.display = "none"));
                if (postUploadInfo) {
                    postUploadInfo.style.display = "none";
                }

                // Create a new FileList object
                const dataTransfer = new DataTransfer();
                Array.from(files).forEach((file) =>
                    dataTransfer.items.add(file)
                );
                fileInput.files = dataTransfer.files;
            }
        }

        if (clearFileBtn) {
            clearFileBtn.addEventListener("click", () => {
                fileInput.value = ""; // Clear the file input
                const dataTransfer = new DataTransfer(); // Create empty FileList
                fileInput.files = dataTransfer.files; // Assign empty FileList

                fileNameContainer.style.display = "none";
                selectFileBtn.style.display = "inline-block";
                if (uploadButton) {
                    uploadButton.style.display = "none";
                }
                // Show upload instructions and post-upload info
                uploadInstructions.forEach(
                    (el) => (el.style.display = "block")
                );
                if (postUploadInfo) {
                    postUploadInfo.style.display = "block";
                }
            });
        }
    };

    // Setup main upload form
    setupDropzone(
        "fvm-upload-form",
        "fvm-file-input",
        "fvm-select-file",
        "fvm-upload-button",
        "fvm-file-name"
    );

    // Setup edit modal dropzone
    // Note: The edit modal form ('edit-form') might not exist on initial page load,
    // but setupDropzone includes checks for the form's existence.
    setupDropzone(
        "edit-form",
        "new_file",
        "fvm-edit-select-file",
        null,
        "fvm-edit-file-name"
    );
});
