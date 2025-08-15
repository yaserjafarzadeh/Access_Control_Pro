<?php
/**
 * Content Manager Class
 * Handles content access control and post/page restrictions
 */

if (!defined('ABSPATH')) {
    exit;
}

class ACP_Content_Manager {

    /**
     * Restricted content for current user
     */
    private $restricted_content = array();

    /**
     * Current user ID
     */
    private $current_user_id = 0;

    /**
     * Constructor
     */
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('admin_init', array($this, 'admin_init'));
    }

    /**
     * Initialize
     */
    public function init() {
        // Load current user content restrictions
        if (is_user_logged_in()) {
            $this->current_user_id = get_current_user_id();
            $this->load_user_content_restrictions();
        }

        // Hook into content queries
        add_action('pre_get_posts', array($this, 'filter_posts_query'));
        add_filter('get_pages', array($this, 'filter_pages'));
        add_filter('wp_get_nav_menu_items', array($this, 'filter_menu_items'), 10, 3);
    }

    /**
     * Admin initialization
     */
    public function admin_init() {
        // Block access to restricted content in admin
        add_action('load-post.php', array($this, 'block_post_edit_access'));
        add_action('load-post-new.php', array($this, 'block_post_create_access'));
        add_action('load-edit.php', array($this, 'block_post_list_access'));

        // Filter content in admin lists
        add_filter('parse_query', array($this, 'filter_admin_queries'));

        // Block content actions
        add_filter('post_row_actions', array($this, 'filter_post_row_actions'), 10, 2);
        add_filter('page_row_actions', array($this, 'filter_page_row_actions'), 10, 2);
    }

    /**
     * Load user content restrictions
     */
    private function load_user_content_restrictions() {
        if (is_super_admin($this->current_user_id)) {
            return; // Super admin has no restrictions
        }

        $restrictions = $this->get_user_content_restrictions($this->current_user_id);
        $this->restricted_content = $restrictions;
    }

    /**
     * Get user content restrictions
     */
    private function get_user_content_restrictions($user_id) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'acp_restrictions';
        $restrictions = array();

        // Get user-specific restrictions
        $user_restrictions = $wpdb->get_row($wpdb->prepare(
            "SELECT restrictions FROM {$table_name} WHERE user_id = %d AND type = 'user'",
            $user_id
        ));

        if ($user_restrictions) {
            $user_data = maybe_unserialize($user_restrictions->restrictions);
            if (isset($user_data['content'])) {
                $restrictions = array_merge_recursive($restrictions, $user_data['content']);
            }
        }

        // Get role-based restrictions
        $user = get_userdata($user_id);
        if ($user && !empty($user->roles)) {
            foreach ($user->roles as $role) {
                $role_restrictions = $wpdb->get_row($wpdb->prepare(
                    "SELECT restrictions FROM {$table_name} WHERE target_value = %s AND type = 'role'",
                    $role
                ));

                if ($role_restrictions) {
                    $role_data = maybe_unserialize($role_restrictions->restrictions);
                    if (isset($role_data['content'])) {
                        $restrictions = array_merge_recursive($restrictions, $role_data['content']);
                    }
                }
            }
        }

        return $restrictions;
    }

    /**
     * Apply content restrictions
     */
    public function apply_restrictions($content_restrictions, $user_id = null) {
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }

        if (is_super_admin($user_id)) {
            return; // Super admin bypass
        }

        $this->restricted_content = array_merge_recursive($this->restricted_content, $content_restrictions);

        // Apply post type restrictions
        if (isset($content_restrictions['post_types'])) {
            $this->apply_post_type_restrictions($content_restrictions['post_types']);
        }

        // Apply category restrictions
        if (isset($content_restrictions['categories'])) {
            $this->apply_category_restrictions($content_restrictions['categories']);
        }

        // Apply specific post restrictions
        if (isset($content_restrictions['posts'])) {
            $this->apply_specific_post_restrictions($content_restrictions['posts']);
        }

        // Apply page restrictions
        if (isset($content_restrictions['pages'])) {
            $this->apply_page_restrictions($content_restrictions['pages']);
        }
    }

    /**
     * Apply post type restrictions
     */
    private function apply_post_type_restrictions($post_types) {
        foreach ($post_types as $post_type) {
            // Remove post type from admin menu
            add_action('admin_menu', function() use ($post_type) {
                if ($post_type === 'post') {
                    remove_menu_page('edit.php');
                } else {
                    remove_menu_page('edit.php?post_type=' . $post_type);
                }
            });

            // Block access to post type pages
            add_action('current_screen', function($screen) use ($post_type) {
                if ($screen->post_type === $post_type && in_array($screen->base, array('post', 'edit'))) {
                    wp_die(
                        __('Access denied. You do not have permission to manage this content type.', 'access-control-pro'),
                        __('Access Denied', 'access-control-pro'),
                        array('response' => 403)
                    );
                }
            });
        }
    }

    /**
     * Apply category restrictions
     */
    private function apply_category_restrictions($categories) {
        add_filter('pre_get_posts', function($query) use ($categories) {
            if (!is_admin() || !$query->is_main_query()) {
                return;
            }

            $tax_query = $query->get('tax_query') ?: array();
            $tax_query[] = array(
                'taxonomy' => 'category',
                'field'    => 'term_id',
                'terms'    => $categories,
                'operator' => 'NOT IN',
            );

            $query->set('tax_query', $tax_query);
        });
    }

    /**
     * Apply specific post restrictions
     */
    private function apply_specific_post_restrictions($post_ids) {
        add_filter('pre_get_posts', function($query) use ($post_ids) {
            if (!is_admin() || !$query->is_main_query()) {
                return;
            }

            $existing_ids = $query->get('post__not_in') ?: array();
            $excluded_ids = array_merge($existing_ids, $post_ids);
            $query->set('post__not_in', $excluded_ids);
        });
    }

    /**
     * Apply page restrictions
     */
    private function apply_page_restrictions($page_ids) {
        add_filter('get_pages', function($pages) use ($page_ids) {
            return array_filter($pages, function($page) use ($page_ids) {
                return !in_array($page->ID, $page_ids);
            });
        });
    }

    /**
     * Filter posts query
     */
    public function filter_posts_query($query) {
        if (empty($this->restricted_content) || is_super_admin($this->current_user_id)) {
            return;
        }

        // Apply post restrictions
        if (isset($this->restricted_content['posts'])) {
            $existing_excluded = $query->get('post__not_in') ?: array();
            $excluded_posts = array_merge($existing_excluded, $this->restricted_content['posts']);
            $query->set('post__not_in', $excluded_posts);
        }

        // Apply category restrictions
        if (isset($this->restricted_content['categories'])) {
            $tax_query = $query->get('tax_query') ?: array();
            $tax_query[] = array(
                'taxonomy' => 'category',
                'field'    => 'term_id',
                'terms'    => $this->restricted_content['categories'],
                'operator' => 'NOT IN',
            );
            $query->set('tax_query', $tax_query);
        }

        // Apply post type restrictions
        if (isset($this->restricted_content['post_types'])) {
            $post_type = $query->get('post_type');
            if ($post_type && in_array($post_type, $this->restricted_content['post_types'])) {
                $query->set('post_type', 'non_existent_type'); // Force empty results
            }
        }
    }

    /**
     * Filter pages
     */
    public function filter_pages($pages) {
        if (empty($this->restricted_content['pages']) || is_super_admin($this->current_user_id)) {
            return $pages;
        }

        return array_filter($pages, function($page) {
            return !in_array($page->ID, $this->restricted_content['pages']);
        });
    }

    /**
     * Filter menu items
     */
    public function filter_menu_items($items, $menu, $args) {
        if (empty($this->restricted_content) || is_super_admin($this->current_user_id)) {
            return $items;
        }

        return array_filter($items, function($item) {
            // Check if menu item links to restricted content
            if ($item->object === 'post' && isset($this->restricted_content['posts'])) {
                return !in_array($item->object_id, $this->restricted_content['posts']);
            }

            if ($item->object === 'page' && isset($this->restricted_content['pages'])) {
                return !in_array($item->object_id, $this->restricted_content['pages']);
            }

            if ($item->object === 'category' && isset($this->restricted_content['categories'])) {
                return !in_array($item->object_id, $this->restricted_content['categories']);
            }

            return true;
        });
    }

    /**
     * Block post edit access
     */
    public function block_post_edit_access() {
        global $post;

        if (!$post || is_super_admin($this->current_user_id)) {
            return;
        }

        if ($this->is_content_restricted($post->ID, $post->post_type)) {
            wp_die(
                __('Access denied. You do not have permission to edit this content.', 'access-control-pro'),
                __('Access Denied', 'access-control-pro'),
                array('response' => 403)
            );
        }
    }

    /**
     * Block post create access
     */
    public function block_post_create_access() {
        if (is_super_admin($this->current_user_id)) {
            return;
        }

        $post_type = $_GET['post_type'] ?? 'post';

        if (isset($this->restricted_content['post_types']) &&
            in_array($post_type, $this->restricted_content['post_types'])) {
            wp_die(
                __('Access denied. You do not have permission to create this type of content.', 'access-control-pro'),
                __('Access Denied', 'access-control-pro'),
                array('response' => 403)
            );
        }
    }

    /**
     * Block post list access
     */
    public function block_post_list_access() {
        if (is_super_admin($this->current_user_id)) {
            return;
        }

        $post_type = $_GET['post_type'] ?? 'post';

        if (isset($this->restricted_content['post_types']) &&
            in_array($post_type, $this->restricted_content['post_types'])) {
            wp_die(
                __('Access denied. You do not have permission to view this content type.', 'access-control-pro'),
                __('Access Denied', 'access-control-pro'),
                array('response' => 403)
            );
        }
    }

    /**
     * Filter admin queries
     */
    public function filter_admin_queries($query) {
        if (!is_admin() || is_super_admin($this->current_user_id)) {
            return;
        }

        // Apply content restrictions to admin queries
        $this->filter_posts_query($query);
    }

    /**
     * Filter post row actions
     */
    public function filter_post_row_actions($actions, $post) {
        if (is_super_admin($this->current_user_id)) {
            return $actions;
        }

        if ($this->is_content_restricted($post->ID, $post->post_type)) {
            // Remove edit, quick edit, trash actions
            unset($actions['edit']);
            unset($actions['inline hide-if-no-js']);
            unset($actions['trash']);
            unset($actions['delete']);

            // Add restriction notice
            $actions['restricted'] = '<span style="color: red;">' . __('Restricted', 'access-control-pro') . '</span>';
        }

        return $actions;
    }

    /**
     * Filter page row actions
     */
    public function filter_page_row_actions($actions, $post) {
        return $this->filter_post_row_actions($actions, $post);
    }

    /**
     * Check if content is restricted
     */
    private function is_content_restricted($post_id, $post_type) {
        // Check specific post restrictions
        if (isset($this->restricted_content['posts']) &&
            in_array($post_id, $this->restricted_content['posts'])) {
            return true;
        }

        // Check post type restrictions
        if (isset($this->restricted_content['post_types']) &&
            in_array($post_type, $this->restricted_content['post_types'])) {
            return true;
        }

        // Check page restrictions
        if ($post_type === 'page' &&
            isset($this->restricted_content['pages']) &&
            in_array($post_id, $this->restricted_content['pages'])) {
            return true;
        }

        // Check category restrictions
        if (isset($this->restricted_content['categories'])) {
            $categories = wp_get_post_categories($post_id);
            if (array_intersect($categories, $this->restricted_content['categories'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get all post types
     */
    public function get_all_post_types() {
        $post_types = get_post_types(array('public' => true), 'objects');

        $formatted_types = array();
        foreach ($post_types as $post_type) {
            $count = wp_count_posts($post_type->name);

            $formatted_types[] = array(
                'name' => $post_type->name,
                'label' => $post_type->label,
                'description' => $post_type->description,
                'public' => $post_type->public,
                'hierarchical' => $post_type->hierarchical,
                'supports' => $post_type->supports ?? array(),
                'count' => array_sum((array) $count),
                'is_restricted' => $this->is_post_type_restricted($post_type->name)
            );
        }

        return $formatted_types;
    }

    /**
     * Get all categories
     */
    public function get_all_categories() {
        $categories = get_categories(array(
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC'
        ));

        $formatted_categories = array();
        foreach ($categories as $category) {
            $formatted_categories[] = array(
                'id' => $category->term_id,
                'name' => $category->name,
                'slug' => $category->slug,
                'description' => $category->description,
                'count' => $category->count,
                'parent' => $category->parent,
                'is_restricted' => $this->is_category_restricted($category->term_id)
            );
        }

        return $formatted_categories;
    }

    /**
     * Get posts for selection
     */
    public function get_posts_for_selection($args = array()) {
        $defaults = array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => 50,
            'orderby' => 'title',
            'order' => 'ASC'
        );

        $args = wp_parse_args($args, $defaults);
        $posts = get_posts($args);

        $formatted_posts = array();
        foreach ($posts as $post) {
            $formatted_posts[] = array(
                'id' => $post->ID,
                'title' => $post->post_title,
                'type' => $post->post_type,
                'status' => $post->post_status,
                'date' => $post->post_date,
                'author' => get_the_author_meta('display_name', $post->post_author),
                'is_restricted' => $this->is_content_restricted($post->ID, $post->post_type)
            );
        }

        return $formatted_posts;
    }

    /**
     * Get pages for selection
     */
    public function get_pages_for_selection() {
        $pages = get_pages(array(
            'post_status' => 'publish',
            'sort_column' => 'post_title',
            'sort_order' => 'ASC'
        ));

        $formatted_pages = array();
        foreach ($pages as $page) {
            $formatted_pages[] = array(
                'id' => $page->ID,
                'title' => $page->post_title,
                'parent' => $page->post_parent,
                'status' => $page->post_status,
                'date' => $page->post_date,
                'author' => get_the_author_meta('display_name', $page->post_author),
                'is_restricted' => $this->is_content_restricted($page->ID, 'page')
            );
        }

        return $formatted_pages;
    }

    /**
     * Check if post type is restricted
     */
    public function is_post_type_restricted($post_type, $user_id = null) {
        if ($user_id === null) {
            $user_id = $this->current_user_id;
        }

        if (is_super_admin($user_id)) {
            return false;
        }

        $restrictions = $this->get_user_content_restrictions($user_id);
        return isset($restrictions['post_types']) && in_array($post_type, $restrictions['post_types']);
    }

    /**
     * Check if category is restricted
     */
    public function is_category_restricted($category_id, $user_id = null) {
        if ($user_id === null) {
            $user_id = $this->current_user_id;
        }

        if (is_super_admin($user_id)) {
            return false;
        }

        $restrictions = $this->get_user_content_restrictions($user_id);
        return isset($restrictions['categories']) && in_array($category_id, $restrictions['categories']);
    }

    /**
     * Check if post is restricted
     */
    public function is_post_restricted($post_id, $user_id = null) {
        if ($user_id === null) {
            $user_id = $this->current_user_id;
        }

        if (is_super_admin($user_id)) {
            return false;
        }

        $restrictions = $this->get_user_content_restrictions($user_id);
        return isset($restrictions['posts']) && in_array($post_id, $restrictions['posts']);
    }

    /**
     * Check if page is restricted
     */
    public function is_page_restricted($page_id, $user_id = null) {
        if ($user_id === null) {
            $user_id = $this->current_user_id;
        }

        if (is_super_admin($user_id)) {
            return false;
        }

        $restrictions = $this->get_user_content_restrictions($user_id);
        return isset($restrictions['pages']) && in_array($page_id, $restrictions['pages']);
    }

    /**
     * Get content restriction statistics
     */
    public function get_restriction_stats() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'acp_restrictions';

        // Count restrictions by type
        $post_restrictions = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table_name} WHERE restrictions LIKE '%post%'"
        );

        $page_restrictions = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table_name} WHERE restrictions LIKE '%page%'"
        );

        $category_restrictions = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table_name} WHERE restrictions LIKE '%categor%'"
        );

        return array(
            'post_restrictions' => $post_restrictions,
            'page_restrictions' => $page_restrictions,
            'category_restrictions' => $category_restrictions,
            'most_restricted_post_types' => $this->get_most_restricted_post_types(),
            'most_restricted_categories' => $this->get_most_restricted_categories()
        );
    }

    /**
     * Get most restricted post types
     */
    private function get_most_restricted_post_types() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'acp_restrictions';

        $restrictions = $wpdb->get_results(
            "SELECT restrictions FROM {$table_name} WHERE restrictions LIKE '%post_types%'",
            ARRAY_A
        );

        $post_type_counts = array();

        foreach ($restrictions as $restriction) {
            $data = maybe_unserialize($restriction['restrictions']);
            if (isset($data['content']['post_types']) && is_array($data['content']['post_types'])) {
                foreach ($data['content']['post_types'] as $post_type) {
                    $post_type_counts[$post_type] = ($post_type_counts[$post_type] ?? 0) + 1;
                }
            }
        }

        arsort($post_type_counts);
        return array_slice($post_type_counts, 0, 5, true);
    }

    /**
     * Get most restricted categories
     */
    private function get_most_restricted_categories() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'acp_restrictions';

        $restrictions = $wpdb->get_results(
            "SELECT restrictions FROM {$table_name} WHERE restrictions LIKE '%categories%'",
            ARRAY_A
        );

        $category_counts = array();

        foreach ($restrictions as $restriction) {
            $data = maybe_unserialize($restriction['restrictions']);
            if (isset($data['content']['categories']) && is_array($data['content']['categories'])) {
                foreach ($data['content']['categories'] as $category_id) {
                    $category_counts[$category_id] = ($category_counts[$category_id] ?? 0) + 1;
                }
            }
        }

        arsort($category_counts);
        return array_slice($category_counts, 0, 5, true);
    }

    /**
     * Log content access attempt
     */
    private function log_content_access_attempt($content_id, $content_type, $user_id) {
        if (class_exists('ACP_Logger')) {
            $logger = new ACP_Logger();
            $logger->log_restriction_attempt($content_type, $content_id, $user_id);
        }
    }

    /**
     * Check if user can edit content
     */
    public function can_user_edit_content($content_id, $content_type, $user_id = null) {
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }

        if (is_super_admin($user_id)) {
            return true;
        }

        // Check if content is restricted
        if ($this->is_content_restricted($content_id, $content_type)) {
            return false;
        }

        // Check if user has capability
        $post_type_obj = get_post_type_object($content_type);
        if ($post_type_obj) {
            return user_can($user_id, $post_type_obj->cap->edit_posts);
        }

        return false;
    }

    /**
     * Get accessible content for user
     */
    public function get_accessible_content($user_id = null, $content_type = 'post') {
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }

        if (is_super_admin($user_id)) {
            return $this->get_posts_for_selection(array('post_type' => $content_type, 'posts_per_page' => -1));
        }

        $restrictions = $this->get_user_content_restrictions($user_id);

        $args = array(
            'post_type' => $content_type,
            'posts_per_page' => -1,
            'post_status' => 'any'
        );

        // Exclude restricted posts
        if (isset($restrictions['posts'])) {
            $args['post__not_in'] = $restrictions['posts'];
        }

        // Exclude restricted categories
        if (isset($restrictions['categories'])) {
            $args['tax_query'] = array(
                array(
                    'taxonomy' => 'category',
                    'field'    => 'term_id',
                    'terms'    => $restrictions['categories'],
                    'operator' => 'NOT IN',
                ),
            );
        }

        return $this->get_posts_for_selection($args);
    }
}
