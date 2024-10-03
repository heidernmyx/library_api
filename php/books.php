<?php

header('Content-Type: application/json'); 
header('Access-Control-Allow-Origin: *'); 

include '../php/connection/connection.php';

class Book {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function addBook($json) {
        if (!$this->validateBookInput($json)) {
            http_response_code(400); // Bad Request
            echo json_encode(['success' => false, 'message' => 'Invalid input.']);
            exit;
        }

        try {
            $this->pdo->beginTransaction();

            // Check if author exists, if not, insert a new author
            $authorId = $this->getOrCreateAuthor($json['author']);

            // Insert the book with additional details
            $stmt = $this->pdo->prepare("INSERT INTO books (Title, AuthorID, ISBN, PublicationDate) VALUES (?, ?, ?, ?)");
            $stmt->execute([$json['title'], $authorId, $json['isbn'], $json['publication_date']]);
            $bookId = $this->pdo->lastInsertId();

            // Handle genres (assume it's an array)
            $this->handleGenres($json['genres'], $bookId);

            $this->pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Book added successfully.', 'book_id' => $bookId]);
        } catch (\PDOException $e) {
            $this->pdo->rollBack();
            http_response_code(500); // Internal Server Error
            echo json_encode(['success' => false, 'message' => 'Failed to add book.', 'error' => $e->getMessage()]);
        }
    }

    private function validateBookInput($json) {
        return !empty($json['title']) && !empty($json['author']) && 
               isset($json['genres']) && is_array($json['genres']) && 
               !empty($json['isbn']) && !empty($json['publication_date']);
    }

    private function getOrCreateAuthor($authorName) {
        // Check if the author exists
        $stmt = $this->pdo->prepare("SELECT AuthorID FROM authors WHERE AuthorName = :name");
        $stmt->bindParam(':name', $authorName, PDO::PARAM_STR);
        $stmt->execute();
        $author = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($author) {
            return $author['AuthorID'];
        } else {
            // Insert a new author
            $stmt = $this->pdo->prepare("INSERT INTO authors (AuthorName) VALUES (?)");
            $stmt->execute([$authorName]);
            return $this->pdo->lastInsertId();
        }
    }

    private function handleGenres($genres, $bookId) {
        foreach ($genres as $genre) {
            // Check if genre exists
            $stmt = $this->pdo->prepare("SELECT GenreId FROM genres WHERE GenreName = :genre");
            $stmt->bindParam(':genre', $genre, PDO::PARAM_STR);
            $stmt->execute();
            $genreData = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($genreData) {
                $genreId = $genreData['GenreId'];
            } else {
                // Insert a new genre
                $stmt = $this->pdo->prepare("INSERT INTO genres (GenreName) VALUES (?)");
                $stmt->execute([$genre]);
                $genreId = $this->pdo->lastInsertId();
            }

            // Create a relationship in books_genre table
            $stmt = $this->pdo->prepare("INSERT INTO books_genre (BookId, GenreId) VALUES (?, ?)");
            $stmt->execute([$bookId, $genreId]);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    $operation = $_GET['operation'];
    $json = isset($_GET['json']) ? json_decode($_GET['json'], true) : null;
}
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $operation = $_POST['operation'];
    $json = isset($_POST['json']) ? json_decode($_POST['json'], true) : null;
}

$book = new Book($pdo);
switch ($operation) {
    case 'addBook':
        $book->addBook($json);
        break;
    default:
        http_response_code(400); // Bad Request
        echo json_encode("Invalid operation.");
        break;
}
