<?php
/**
 * Plugin Name:       Books Plugin
 * Description:       Example plugin for books that calls a laravel microservice.
 * Version:           0.1.0
 * Author:            Erinn Szarek Card
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}


// Register Custom Post Type
function create_book_post_type() {
    $labels = array(
        'name'          => 'Books',
        'singular_name' => 'Book',
        'menu_name'     => 'Books',
        'add_new'       => 'Add New Book',
        'add_new_item'  => 'Add New Book',
        'edit_item'     => 'Edit Book',
        'new_item'      => 'New Book',
        'view_item'     => 'View Book',
        'search_items'  => 'Search Books',
        'not_found'     => 'No books found',
        'not_found_in_archive' => 'No books found in archive'
    );

    $args = array(
        'labels'        => $labels,
        'public'        => true,
        'has_archive'   => true,
        'menu_position' => 5,
        'menu_icon'     => 'dashicons-book',
        'supports'      => array('title', 'editor', 'thumbnail'),
        'show_in_rest'  => true
    );
    
    register_post_type('book', $args);

}
add_action('init', 'create_book_post_type');

//custom book post meta
function register_book_meta() {
    register_post_meta('book', 'book_author', array(
        'type' => 'string',
        'description' => 'Author of the book',
        'single' => true,
        'show_in_rest' => true,
    ));
}
add_action('init', 'register_book_meta');



function create_book_genre_taxonomy() {
    $labels = array(
        'name'              => 'Genres',
        'singular_name'     => 'Genre',
        'search_items'      => 'Search Genres',
        'all_items'         => 'All Genres',
        'parent_item'       => 'Parent Genre',
        'parent_item_colon' => 'Parent Genre:',
        'edit_item'         => 'Edit Genre',
        'update_item'       => 'Update Genre',
        'add_new_item'      => 'Add New Genre',
        'new_item_name'     => 'New Genre Name',
        'menu_name'         => 'Genres',
    );

    $args = array(
        'hierarchical'      => true,  
        'show_ui'            => true,
        'show_admin_column'  => true,
        'query_var'          => true,
        'rewrite'            => array('slug' => 'genre'),
    );

    register_taxonomy('book_genre', array('book'), $args);  
}

add_action('init', 'create_book_genre_taxonomy');




function book_details_callback($post) {
    $author = get_post_meta($post->ID, 'book_author', true);
    $selected_genre = wp_get_post_terms($post->ID, 'book_genre', ['fields' => 'ids']); // Get selected genre(s)
    $selected_genre = !empty($selected_genre) ? $selected_genre[0] : ''; // Get the first genre if available

    ?>
    <label for="book_author">Author:</label>
    <input type="text" name="book_author" value="<?php echo esc_attr($author); ?>" style="width:100%;" />

    <label for="book_genre">Genre:</label>
    <select name="book_genre" style="width:100%;">
        <option value="">Select Genre</option>
        <?php
        $genres = get_terms([
            'taxonomy'   => 'book_genre',
            'hide_empty' => false,
        ]);

        foreach ($genres as $genre) {
            echo '<option value="' . esc_attr($genre->term_id) . '" ' . selected($selected_genre, $genre->term_id, false) . '>' . esc_html($genre->name) . '</option>';
        }
        ?>
    </select>
    <?php
}
// Save Author and Genre when the post is saved
function save_book_details($post_id) {
    if (isset($_POST['book_author'])) {
        update_post_meta($post_id, 'book_author', sanitize_text_field($_POST['book_author']));
    }

    if (!empty($_POST['book_genre'])) {
        $genre_id = intval($_POST['book_genre']); 
        wp_set_post_terms($post_id, [$genre_id], 'book_genre');
    }
}

add_action('save_post', 'save_book_details');


//Admin Panel
function add_books_meta_boxes() {
    add_meta_box('book_details', 'Book Details', 'book_details_callback', 'book', 'normal', 'high');
}
add_action('add_meta_boxes', 'add_books_meta_boxes');




// Fetch book data from Laravel microservice
function fetch_book_data($post_id) {
    // Make sure we're only working with 'book' post type
    if (get_post_type($post_id) !== 'book') {
        return;
    }

    // Avoid infinite loop when updating post metadata
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    // Get the genre of the current book
    $genre = get_post_meta($post_id, 'book_genre', true);

    // Fetch book data from the microservice (Laravel)
    $response = wp_remote_get('http://127.0.0.1:8000/api/book/' . $post_id);
    
    if (is_wp_error($response)) {
        return;
    }

    // Retrieve the body of the response
    $body = wp_remote_retrieve_body($response);
    
    // Decode the JSON response
    $book_data = json_decode($body, true);

    // Check if data was returned before updating post meta
    if (!empty($book_data)) {
        update_post_meta($post_id, '_book_extra_data', $book_data);

        // Get recommendations from the microservice
        if (isset($book_data['recommendations'])) {
            update_post_meta($post_id, '_recommended_books', $book_data['recommendations']);
        }
    }
}


// Attach the function to save_post action
add_action('save_post', 'fetch_book_data');


function display_books_shortcode() {
    if (!is_singular('book')) {
        return '<p>Please use this shortcode on a book page.</p>';
    }

    global $post;
    $book_id = $post->ID;
    $book_title = get_the_title($book_id);
    $book_data = get_book_description($book_id); // Fetch data from API
    $author = get_post_meta($book_id, 'book_author', true);
    $author_text = !empty($author) ? esc_html($author) : 'Unknown author';

    // Get description
    $description = isset($book_data['description']) ? esc_html($book_data['description']) : 'Description not available.';
    $genres = wp_get_post_terms($book_id, 'book_genre', ['fields' => 'names']);
    $genre_list = !empty($genres) ? implode(', ', $genres) : 'No genre specified';
    // Get recommended books
    $recommended_books = get_book_recommendations($book_id);

    // HTML Output
    $output = '<div class="book-details">';
    $output .= '<h2>' . esc_html($book_title) . '</h2>';
    $output .= '<p><strong>Genre:</strong> ' . esc_html($genre_list) . '</p>';
    $output .= '<p><strong>Author:</strong> ' . esc_html($author) . '</p>';
    $output .= '<p><strong>Description:</strong> ' . $description . '</p>';

    // Display recommendations if available
    if (!empty($recommended_books)) {
        $output .= '<div class="book-recommendations recommendations-bar">';
        $output .= '<h3>Recommended Books</h3>';
        $output .= '<div class="book-row">';

        foreach ($recommended_books as $recommended_book) {
            if (isset($recommended_book['ID'], $recommended_book['post_title'])) {
                $book_url = get_permalink($recommended_book['ID']);
                $book_image = get_the_post_thumbnail($recommended_book['ID'], 'thumbnail') ?: '<div class="placeholder-img">No Image</div>';

                $output .= '<div class="book-item">';
                $output .= '<a href="' . esc_url($book_url) . '">';
                $output .= $book_image; // Display book image
                $output .= '<p>' . esc_html($recommended_book['post_title']) . '</p>';
                $output .= '</a>';
                $output .= '</div>';
            }
        }

        $output .= '</div>'; // End book-row
        $output .= '</div>'; // End book-recommendations
    }

    $output .= '</div>'; // End book-details

    // Add CSS styles
    $output .= '<style>
        .book-recommendations { margin-top: 20px; }
    
        .placeholder-img { width: 100px; height: 150px; background: #ddd; display: flex; align-items: center; justify-content: center; font-size: 12px; }
        .recommendations-bar {
    background: #f8f8f8;
    padding: 10px;
    margin-bottom: 20px;
    border-radius: 5px;
    }


    .book-item a:hover {
        background: #ccc;
    }
        .book-row {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    justify-content: start; /* Adjust alignment */
}

.book-item {
    text-align: center;
    min-width: 120px;
}

.book-item img {
    max-width: 100px;
    height: auto;
    border-radius: 5px;
}

.book-item p {
    margin: 5px 0;
    font-size: 14px;
}

    </style>';

    return $output;
}
// Helper function to get book description from the microservice
function get_book_description($book_id) {
    $response = wp_remote_get('http://127.0.0.1:8000/api/book/' . $book_id);  // Call the microservice
    if (is_wp_error($response)) {
        return [];  // Return an empty array if there's an error
    }

    $body = wp_remote_retrieve_body($response);
    $book_data = json_decode($body, true);

    return $book_data;  // Return the book data with description
}

// Helper function to get book recommendations from the microservice
function get_book_recommendations($book_id) {
    $response = wp_remote_get('http://127.0.0.1:8000/api/book/' . $book_id);  // Call the microservice
    if (is_wp_error($response)) {
        return [];  // Return an empty array if there's an error
    }

    $body = wp_remote_retrieve_body($response);
    $book_data = json_decode($body, true);

    // Ensure recommendations are returned correctly
    if (isset($book_data['recommendations']) && is_array($book_data['recommendations'])) {
        return $book_data['recommendations'];
    }

    return [];  // Return empty array if no recommendations
}
// Register the shortcode properly
add_shortcode('display_books', 'display_books_shortcode');


