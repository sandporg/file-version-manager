(function ($) {
    "use strict";

    $(function () {
        // --- Move FVM Admin Toolbar & Header ---
        const $toolbar = $(".fvm-admin-toolbar");
        const $header = $(".fvm-admin-header");
        const $adminBar = $("#wpadminbar");

        if ($toolbar.length && $adminBar.length) {
            $toolbar.insertAfter($adminBar);
            // Optional: Add a class to indicate it's been moved
            $toolbar.addClass("fvm-element-moved");
            // console.log('FVM Toolbar moved.');
        }

        if (
            $header.length &&
            $toolbar.length &&
            $toolbar.hasClass("fvm-element-moved")
        ) {
            // Insert header after the (now moved) toolbar
            $header.insertAfter($toolbar);
            $header.addClass("fvm-element-moved");
            // console.log('FVM Header moved.');
        } else if ($header.length && $adminBar.length) {
            // Fallback: If toolbar didn't move for some reason, move header after admin bar
            $header.insertAfter($adminBar);
            $header.addClass("fvm-element-moved");
            // console.log('FVM Header moved (fallback).');
        }

        // Add a class to body when elements are potentially moved to allow specific styling
        if (($toolbar.length || $header.length) && $adminBar.length) {
            $("body").addClass("fvm-elements-processed");
        }
    });
})(jQuery);
