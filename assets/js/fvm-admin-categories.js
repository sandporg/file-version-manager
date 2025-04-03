document.addEventListener("DOMContentLoaded", function () {
    const modal = document.getElementById("edit-modal");
    const overlay = document.getElementById("edit-modal-overlay");
    const categoryIdDisplay = document.getElementById("category_id"); // Renamed for clarity
    const editCategoryIdInput = document.getElementById("edit-category-id");
    const editCategoryNonceInput = document.getElementById(
        "edit_category_nonce"
    ); // Assuming this exists
    const editCatNameInput = document.getElementById("edit_cat_name");
    const editCatSlugInput = document.getElementById("edit_cat_slug");
    const editCatDescInput = document.getElementById("edit_cat_description");
    const editCatParentSelect = document.getElementById("edit_cat_parent_id");
    const editCatExcludeCheckbox = document.getElementById(
        "edit_cat_exclude_browser"
    );

    // Ensure modal and overlay exist
    if (!modal || !overlay) {
        console.error("Category Edit Modal or Overlay not found!");
        return;
    }

    // Function to toggle body scroll
    const toggleBodyScroll = (disable) => {
        document.body.style.overflow = disable ? "hidden" : "";
    };

    // Edit category link handler
    document.querySelectorAll(".edit-category").forEach((link) => {
        link.addEventListener("click", function (e) {
            e.preventDefault();
            const categoryId = this.getAttribute("data-category-id");
            // const nonce = this.getAttribute("data-nonce"); // Nonce for AJAX fetched later
            showEditModal(categoryId);
            populateEditModal(categoryId);
        });
    });

    function showEditModal(categoryId) {
        // Ensure elements are displayed before adding active class for transition
        modal.style.display = "flex";
        overlay.style.display = "block";

        // Force reflow
        void modal.offsetHeight;
        void overlay.offsetHeight;

        // Add active class to trigger transition
        modal.classList.add("active");
        overlay.classList.add("active");
        toggleBodyScroll(true);

        // Clear previous data and show loading indicators
        if (categoryIdDisplay)
            categoryIdDisplay.textContent = fvmCategoryData.text.loading;
        if (editCatNameInput)
            editCatNameInput.value = fvmCategoryData.text.loading;
        if (editCatSlugInput)
            editCatSlugInput.value = fvmCategoryData.text.loading;
        if (editCatDescInput)
            editCatDescInput.value = fvmCategoryData.text.loading;
        if (editCatParentSelect)
            editCatParentSelect.innerHTML =
                '<option value="0">' +
                fvmCategoryData.text.loading +
                "</option>";
        if (editCatExcludeCheckbox) editCatExcludeCheckbox.checked = false;
        if (editCategoryIdInput) editCategoryIdInput.value = "";
        // Clear nonce field if applicable (it will be populated by AJAX)
        if (editCategoryNonceInput) editCategoryNonceInput.value = "";
    }

    function populateEditModal(categoryId) {
        // Use localized data for AJAX URL and nonce check
        if (
            !fvmCategoryData ||
            !fvmCategoryData.ajax_url ||
            !fvmCategoryData.get_category_nonce
        ) {
            console.error("fvmCategoryData not available for AJAX call.");
            alert("Configuration error loading category data.");
            closeEditModal();
            return;
        }

        // Construct the AJAX URL
        const ajaxUrl = `${fvmCategoryData.ajax_url}?action=get_category_data&category_id=${categoryId}&_ajax_nonce=${fvmCategoryData.get_category_nonce}`;

        fetch(ajaxUrl)
            .then((response) => {
                if (!response.ok) {
                    return response
                        .json()
                        .then((err) => {
                            throw new Error(
                                err.data?.message ||
                                    `HTTP error ${response.status}`
                            );
                        })
                        .catch(() => {
                            throw new Error(`HTTP error ${response.status}`); // Fallback if JSON parsing fails
                        });
                }
                return response.json();
            })
            .then((data) => {
                if (data.success) {
                    const category = data.data;
                    const sanitizedCategoryId = parseInt(category.id, 10);

                    if (isNaN(sanitizedCategoryId)) {
                        throw new Error(
                            "Invalid category ID received from server"
                        );
                    }

                    if (editCategoryIdInput)
                        editCategoryIdInput.value = sanitizedCategoryId;
                    if (categoryIdDisplay)
                        categoryIdDisplay.textContent =
                            "ID: " + sanitizedCategoryId;
                    if (editCategoryNonceInput)
                        editCategoryNonceInput.value = category.nonce; // Set the specific nonce for the form
                    if (editCatNameInput)
                        editCatNameInput.value = sanitizeHTML(
                            category.cat_name || ""
                        );
                    if (editCatSlugInput)
                        editCatSlugInput.value = sanitizeHTML(
                            category.cat_slug || ""
                        );
                    if (editCatDescInput)
                        editCatDescInput.value = sanitizeHTML(
                            category.cat_description || ""
                        );
                    if (editCatExcludeCheckbox)
                        editCatExcludeCheckbox.checked =
                            category.cat_exclude_browser == "1";

                    // Populate parent category dropdown
                    if (editCatParentSelect) {
                        editCatParentSelect.innerHTML =
                            '<option value="0">None</option>'; // Start fresh
                        if (
                            category.parent_categories &&
                            Array.isArray(category.parent_categories)
                        ) {
                            function addOptions(categories, depth = 0) {
                                categories.forEach((cat) => {
                                    const option =
                                        document.createElement("option");
                                    const catIdInt = parseInt(cat.id, 10) || 0;
                                    option.value = catIdInt;
                                    option.textContent =
                                        "\u00A0".repeat(depth * 3) +
                                        sanitizeHTML(cat.cat_name || "Unnamed");
                                    option.selected =
                                        catIdInt ===
                                        parseInt(category.cat_parent_id, 10);
                                    editCatParentSelect.appendChild(option);

                                    if (
                                        cat.children &&
                                        Array.isArray(cat.children) &&
                                        cat.children.length > 0
                                    ) {
                                        addOptions(cat.children, depth + 1);
                                    }
                                });
                            }
                            addOptions(category.parent_categories);
                        }
                    } else {
                        console.warn(
                            "Parent category select element not found."
                        );
                    }
                } else {
                    const errorMsg =
                        data.data?.message ||
                        "Unknown error fetching category data";
                    console.error("Failed to fetch category data:", errorMsg);
                    alert(fvmCategoryData.text.error + ": " + errorMsg);
                    closeEditModal();
                }
            })
            .catch((error) => {
                console.error(
                    "Error fetching or processing category data:",
                    error
                );
                alert(fvmCategoryData.text.error + ": " + error.message);
                closeEditModal();
            });
    }

    // Basic HTML sanitization helper (same as before)
    function sanitizeHTML(str) {
        const temp = document.createElement("div");
        temp.textContent = str;
        return temp.innerHTML;
    }

    function closeEditModal() {
        // Function to handle setting display to none after transition
        const handleTransitionEnd = (event) => {
            // Only react to the opacity transition ending on the modal itself
            if (event.target === modal && event.propertyName === "opacity") {
                modal.style.display = "none";
                overlay.style.display = "none";
                // IMPORTANT: Remove the listener after it's executed
                modal.removeEventListener("transitionend", handleTransitionEnd);
            }
        };

        // 1. Add the event listener *before* starting the transition
        modal.addEventListener("transitionend", handleTransitionEnd);

        // 2. Remove active class to trigger the closing transition
        modal.classList.remove("active");
        overlay.classList.remove("active");
        toggleBodyScroll(false); // Enable body scroll
    }

    // Update event listeners for closing the modal
    document.querySelectorAll(".close, .cancel-edit").forEach((button) => {
        button.addEventListener("click", function (e) {
            e.preventDefault();
            closeEditModal();
        });
    });

    // Use overlay for click-outside
    overlay.addEventListener("click", function (event) {
        // Check if the click was directly on the overlay, not its children
        if (event.target === overlay) {
            closeEditModal();
        }
    });

    // Escape key listener
    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape" && modal.classList.contains("active")) {
            closeEditModal();
        }
    });
});
