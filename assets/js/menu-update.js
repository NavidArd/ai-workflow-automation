(function($) {
    function updateMenuCount() {
        $.ajax({
            url: wpAiWorkflowsMenu.ajaxurl,
            type: 'POST',
            data: {
                action: 'wp_ai_workflows_update_menu_count',
                nonce: wpAiWorkflowsMenu.nonce
            },
            success: function(response) {
                if (response.success) {
                    var $menuItem = $('#toplevel_page_wp-ai-workflows .wp-menu-name');
                    var $count = $menuItem.find('.update-plugins');
                    if (response.data.count > 0) {
                        if ($count.length) {
                            $count.find('.plugin-count').text(response.data.count);
                        } else {
                            $menuItem.append(' <span class="update-plugins count-' + response.data.count + '"><span class="plugin-count">' + response.data.count + '</span></span>');
                        }
                    } else {
                        $count.remove();
                    }
                }
            }
        });
    }

    $(document).ready(function() {
        setInterval(updateMenuCount, 60000); // Update every 60 seconds
    });
})(jQuery);