<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class BookController extends Controller
{
    public function getBook($id)
    {
        // Query the WordPress database
        $book = DB::connection()->table('wp_posts')
            ->where('ID', $id)
            ->where('post_type', 'book')
            ->first();

        if (!$book) {
            return response()->json(['error' => 'Book not found'], 404);
        }

        // Fetch book metadata (author and genre)
        $author = DB::connection()->table('wp_postmeta')
            ->where('post_id', $book->ID)
            ->where('meta_key', 'book_author')
            ->value('meta_value');

        $genre = DB::connection()->table('wp_terms')
            ->join('wp_term_taxonomy', 'wp_terms.term_id', '=', 'wp_term_taxonomy.term_id')
            ->join('wp_term_relationships', 'wp_term_taxonomy.term_taxonomy_id', '=', 'wp_term_relationships.term_taxonomy_id')
            ->where('wp_term_taxonomy.taxonomy', 'book_genre')
            ->where('wp_term_relationships.object_id', $book->ID)
            ->value('wp_terms.name');
        



        // Fetch book description from external API (Google Books)
        $description = $this->fetchBookDescription($book->post_title, $author);
        
        if (!$description) {
            return response()->json(['error' => 'Description not found for the book'], 404);
        }

        // Get recommended books
        $recommended_books = $this->getRecommendationsByGenre($genre, $book->ID);

        return response()->json([
            'id' => $book->ID,
            'title' => $book->post_title,
            'author' => $author,
            'genre' => $genre,
            'description' => $description,
            'recommendations' => $recommended_books,
        ]);
    }

    // Fetch recommended books by genre
    private function getRecommendationsByGenre($genre, $exclude_book_id = null)
    {
       
        $query = DB::connection()->table('wp_posts')
        ->join('wp_term_relationships', 'wp_posts.ID', '=', 'wp_term_relationships.object_id')
        ->join('wp_term_taxonomy', 'wp_term_relationships.term_taxonomy_id', '=', 'wp_term_taxonomy.term_taxonomy_id')
        ->join('wp_terms', 'wp_terms.term_id', '=', 'wp_term_taxonomy.term_id')
        ->where('wp_terms.name', $genre) // Match the genre
        ->where('wp_posts.post_type', 'book') 
        ->where('wp_posts.post_status', 'publish') 
        ->limit(3);


    if ($exclude_book_id) {
        $query->where('wp_posts.ID', '!=', $exclude_book_id);
    }

    return $query->get(['wp_posts.ID', 'wp_posts.post_title', 'wp_posts.guid']); 
    }



    // Fetch book description from Google Books API
    private function fetchBookDescription($title, $author)
    {
        $query = urlencode($title . ' ' . $author);
        $url = "https://www.googleapis.com/books/v1/volumes?q=$query";

        $response = Http::withOptions([
            'verify' => false, // Disable SSL certificate verification FOR MY COMPUTER ONLY, this is bad practice but it wouldn't run.
        ])->get($url);
       
        $data = $response->json();

        if (!empty($data['items'][0]['volumeInfo']['description'])) {
            return $data['items'][0]['volumeInfo']['description'];
        }

        return null;
    }
}
