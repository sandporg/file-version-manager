document.addEventListener("DOMContentLoaded", function () {
    // --- Copy Shortcode ---
    document.querySelectorAll(".copy-shortcode").forEach((button) => {
        button.addEventListener("click", function (e) {
            e.preventDefault();
            const shortcode = this.getAttribute("data-shortcode");
            copyToClipboard(shortcode, this);
        });
    });

    function copyToClipboard(text, button) {
        const textArea = document.createElement("textarea");
        textArea.value = text;
        document.body.appendChild(textArea);
        textArea.select();

        try {
            document.execCommand("copy");
            const originalText = button.textContent;
            button.textContent = "Copied!";
            setTimeout(function () {
                button.textContent = originalText;
            }, 2000);
        } catch (err) {
            console.error("Unable to copy to clipboard", err);
        }

        document.body.removeChild(textArea);
    }

    // --- Edit Modal ---
    const modal = document.getElementById("edit-modal");
    const overlay = document.getElementById("edit-modal-overlay");
    const fileIdInput = document.getElementById("edit-file-id");
    const fileIdDisplay = document.getElementById("file_id");
    const fileNameInput = document.getElementById("file_name");
    const fileDisplayNameInput = document.getElementById("file_display_name");
    const fileDescriptionInput = document.getElementById("file_description");
    const fileUrlInput = document.getElementById("file_url");
    const md5HashInput = document.getElementById("md5_hash");
    const sha256HashInput = document.getElementById("sha256_hash");
    const fileVersionInput = document.getElementById("file_version");
    const fileOfflineInput = document.getElementById("file_offline");
    const categoriesContainer = document.getElementById("file_categories");
    const newFileInput = document.getElementById("new_file");
    const editFileNameDisplay = document.getElementById("fvm-edit-file-name");
    const editFileNameContainer = document.querySelector(
        "#edit-form .fvm-file-name-container"
    );
    const editSelectFileBtn = document.getElementById("fvm-edit-select-file");
    const editUploadInstructions = document.querySelectorAll(
        "#edit-form .fvm-upload-instructions"
    );
    const editPostUploadInfo = document.querySelector(
        "#edit-form .post-upload-ui"
    );

    // Ensure modal and overlay exist before adding listeners
    if (!modal || !overlay) {
        return;
    }

    // Function to toggle body scroll
    const toggleBodyScroll = (disable) => {
        document.body.style.overflow = disable ? "hidden" : "";
    };

    document.querySelectorAll(".edit-file").forEach((link) => {
        link.addEventListener("click", function (e) {
            e.preventDefault();
            const fileId = this.getAttribute("data-file-id");
            const nonce = this.getAttribute("data-nonce");
            if (!nonce) {
                console.error("Edit link is missing the data-nonce attribute.");
                alert("Could not open edit modal: security token missing.");
                return;
            }
            showEditModal(fileId);
            populateEditModal(fileId, nonce);
        });
    });

    function showEditModal(fileId) {
        // Ensure elements are displayed before adding active class for transition
        modal.style.display = "flex"; // Use flex as defined in CSS
        overlay.style.display = "block"; // Or 'block' if that's its default

        // Force reflow to ensure display change is registered before transition starts
        // Reading a property like offsetHeight is a common way to trigger reflow
        modal.offsetHeight;
        overlay.offsetHeight;

        // Add active class to trigger transition
        modal.classList.add("active");
        overlay.classList.add("active");
        toggleBodyScroll(true); // Disable body scroll

        // Clear previous data and show loading indicators
        if (fileIdDisplay) fileIdDisplay.textContent = "Loading...";
        if (newFileInput) newFileInput.value = "";
        if (fileNameInput) fileNameInput.value = "Loading...";
        if (fileDisplayNameInput) fileDisplayNameInput.value = "Loading...";
        if (fileDescriptionInput) fileDescriptionInput.value = "Loading...";
        if (fileUrlInput) fileUrlInput.value = "Loading...";
        if (md5HashInput) md5HashInput.value = "Loading...";
        if (sha256HashInput) sha256HashInput.value = "Loading...";
        if (categoriesContainer) categoriesContainer.innerHTML = "Loading...";
        if (fileVersionInput) fileVersionInput.value = "";
        if (fileOfflineInput) fileOfflineInput.checked = false;
    }

    function populateEditModal(fileId, nonce) {
        // Access localized data (check if it exists)
        if (
            typeof fvmAdminModalData === "undefined" ||
            !fvmAdminModalData.ajax_url ||
            !fvmAdminModalData.get_file_nonce
        ) {
            console.error(
                "fvmAdminModalData is not defined or missing properties."
            );
            alert("Could not load file details: Configuration error.");
            closeEditModal(); // Close modal if config is missing
            return;
        }

        // Use the nonce passed from the clicked link for the AJAX call
        const ajaxNonce = nonce;

        fetch(
            `${fvmAdminModalData.ajax_url}?action=get_file_data&file_id=${fileId}&_ajax_nonce=${ajaxNonce}`
        )
            .then((response) => {
                if (!response.ok) {
                    return response
                        .json()
                        .then((err) => {
                            const message =
                                err && err.data && err.data.message
                                    ? err.data.message
                                    : `HTTP error! status: ${response.status}`;
                            throw new Error(message);
                        })
                        .catch((jsonError) => {
                            console.error(
                                "Error parsing JSON response or accessing message:",
                                jsonError
                            );
                            throw new Error(
                                `HTTP error! status: ${response.status}. Could not parse error details.`
                            );
                        });
                }
                return response.json();
            })
            .then((data) => {
                if (data.success) {
                    const file = data.data;

                    if (fileIdInput) fileIdInput.value = file.id;
                    if (fileIdDisplay)
                        fileIdDisplay.textContent = "ID: " + file.id;
                    // The form nonce (fvm_update_file_nonce) is handled by PHP and shouldn't be modified here.
                    if (fileNameInput)
                        fileNameInput.value = file.file_name || "";
                    if (fileDisplayNameInput)
                        fileDisplayNameInput.value =
                            file.file_display_name || "";
                    if (fileDescriptionInput)
                        fileDescriptionInput.value =
                            file.file_description || "";
                    if (fileUrlInput) fileUrlInput.value = file.file_url || "";
                    if (md5HashInput)
                        md5HashInput.value = file.file_hash_md5 || "";
                    if (sha256HashInput)
                        sha256HashInput.value = file.file_hash_sha256 || "";
                    if (fileVersionInput)
                        fileVersionInput.value = file.file_version || "1.0";
                    if (fileOfflineInput)
                        fileOfflineInput.checked = file.file_offline == "1";

                    // Populate categories
                    if (
                        categoriesContainer &&
                        Array.isArray(file.all_categories)
                    ) {
                        categoriesContainer.innerHTML = "";
                        // Use the new simple array of assigned IDs
                        const assignedCategoryIds = Array.isArray(
                            file.assigned_category_ids
                        )
                            ? file.assigned_category_ids // Already an array of integers
                            : [];

                        const renderCategories = (categories, depth = 0) => {
                            categories.forEach((category) => {
                                const label = document.createElement("label");
                                label.style.paddingLeft = `${depth * 15}px`;
                                label.style.display = "block";
                                label.style.marginBottom = "5px";

                                const checkbox =
                                    document.createElement("input");
                                checkbox.type = "checkbox";
                                checkbox.name = "file_categories[]";
                                checkbox.value = category.id;
                                checkbox.checked = assignedCategoryIds.includes(
                                    parseInt(category.id, 10)
                                );

                                label.appendChild(checkbox);
                                label.appendChild(
                                    document.createTextNode(
                                        ` ${sanitizeHTML(
                                            category.cat_name || "Unnamed"
                                        )}`
                                    )
                                );
                                categoriesContainer.appendChild(label);

                                if (
                                    category.children &&
                                    Array.isArray(category.children) &&
                                    category.children.length > 0
                                ) {
                                    renderCategories(
                                        category.children,
                                        depth + 1
                                    );
                                }
                            });
                        };

                        renderCategories(file.all_categories);
                    } else {
                        if (categoriesContainer)
                            categoriesContainer.innerHTML =
                                "No categories found.";
                    }
                } else {
                    console.error(
                        "Failed to fetch file data:",
                        data.data?.message || "Unknown error"
                    );
                    alert(
                        "Error: Could not load file details. " +
                            (data.data?.message || "Please try again.")
                    );
                    closeEditModal(); // Close modal on error
                }
            })
            .catch((error) => {
                console.error("Error fetching file data:", error);
                alert(
                    "An error occurred while fetching file details: " +
                        error.message
                );
                closeEditModal(); // Close modal on error
            });
    }

    // Basic HTML sanitization helper
    function sanitizeHTML(str) {
        const temp = document.createElement("div");
        temp.textContent = str;
        return temp.innerHTML;
    }

    function closeEditModal() {
        modal.classList.remove("active");
        overlay.classList.remove("active");
        toggleBodyScroll(false); // Enable body scroll

        // Add event listener for transition end
        const onTransitionEnd = (event) => {
            // Ensure we're reacting to the opacity transition ending on the modal itself
            if (event.target === modal && event.propertyName === "opacity") {
                modal.style.display = "none";
                overlay.style.display = "none";
                // Remove the listener once executed
                modal.removeEventListener("transitionend", onTransitionEnd);
            }
        };

        modal.addEventListener("transitionend", onTransitionEnd);

        // Reset the file input and related elements in the edit modal
        if (newFileInput) {
            newFileInput.value = "";
            const dataTransfer = new DataTransfer();
            newFileInput.files = dataTransfer.files; // Assign empty FileList
        }
        if (editFileNameContainer) {
            editFileNameContainer.style.display = "none";
        }
        if (editSelectFileBtn) {
            editSelectFileBtn.style.display = "inline-block";
        }
        if (editUploadInstructions) {
            editUploadInstructions.forEach(
                (el) => (el.style.display = "block")
            );
        }
        if (editPostUploadInfo) {
            editPostUploadInfo.style.display = "block";
        }
        if (editFileNameDisplay) {
            editFileNameDisplay.textContent = "";
        }
    }

    document.querySelectorAll(".close, .cancel-edit").forEach((button) => {
        button.addEventListener("click", function (e) {
            e.preventDefault();
            closeEditModal();
        });
    });

    window.addEventListener("click", function (event) {
        // Use event.target === overlay to check if the click was directly on the overlay
        if (event.target === overlay) {
            closeEditModal();
        }
    });

    // Close modal on Escape key press
    document.addEventListener("keydown", function (event) {
        // Check if the modal has the 'active' class
        if (event.key === "Escape" && modal.classList.contains("active")) {
            closeEditModal();
        }
    });
});
