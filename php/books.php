<?php
// books.php

// Set headers to handle CORS and JSON response
header("Access-Control-Allow-Origin: http://localhost:3000"); // Replace with your frontend URL
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Include the database connection
require 'connection/connection.php';

// Fetch all books, including updates and external information
function fetchBooks($conn, $search = '', $limit = 10, $offset = 0) {
    $search = "%$search%";
    $sql = "
        SELECT 
            b.BookID, 
            b.Title, 
            b.Author, 
            b.ISBN, 
            b.PublicationDate, 
            b.Genre, 
            b.Location, 
            b.TotalCopies, 
            b.AvailableCopies, 
            b.Description, 
            bp.Name AS ProviderName,
            bu.Status AS UpdateStatus,
            bu.RequestDate AS LastUpdateDate,
            eb.ExternalSource,
            eb.AdditionalInfo AS ExternalInfo,
            eb.LastUpdated AS ExternalLastUpdated
        FROM 
            book b
        LEFT JOIN 
            bookprovider bp ON b.ProviderID = bp.ProviderID
        LEFT JOIN 
            bookupdate bu ON b.BookID = bu.BookID
        LEFT JOIN 
            externalbookinfo eb ON b.BookID = eb.BookID
        WHERE
            b.Title LIKE :search OR
            b.Author LIKE :search OR
            b.ISBN LIKE :search
        ORDER BY 
            b.BookID
        LIMIT :limit OFFSET :offset;
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':search', $search, PDO::PARAM_STR);
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Add a new book
function addBook($conn, $title, $author, $isbn, $publicationDate, $genre, $location, $totalCopies, $availableCopies, $providerID) {
    $sql = 'INSERT INTO book (Title, Author, ISBN, PublicationDate, Genre, Location, TotalCopies, AvailableCopies, ProviderID) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)';
    $stmt = $conn->prepare($sql);
    return $stmt->execute([$title, $author, $isbn, $publicationDate, $genre, $location, $totalCopies, $availableCopies, $providerID]);
}

// Update an existing book
function updateBook($conn, $bookID, $title, $author, $isbn, $publicationDate, $genre, $location, $totalCopies, $availableCopies, $providerID) {
    $sql = 'UPDATE book SET Title = ?, Author = ?, ISBN = ?, PublicationDate = ?, Genre = ?, Location = ?, TotalCopies = ?, AvailableCopies = ?, ProviderID = ? WHERE BookID = ?';
    $stmt = $conn->prepare($sql);
    return $stmt->execute([$title, $author, $isbn, $publicationDate, $genre, $location, $totalCopies, $availableCopies, $providerID, $bookID]);
}

// Archive a book (soft delete)
function archiveBook($conn, $bookID) {
    $sql = 'UPDATE book SET AvailableCopies = 0 WHERE BookID = ?';
    $stmt = $conn->prepare($sql);
    return $stmt->execute([$bookID]);
}

// Handle the request
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'fetch':
        $search = $_GET['query'] ?? '';
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
        $books = fetchBooks($conn, $search, $limit, $offset);
        echo json_encode($books);
        break;
    
    case 'add':
        // TODO: Implement authentication if needed
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) {
            http_response_code(400);
            echo json_encode(['message' => 'Invalid JSON.']);
            exit();
        }
        // Validate required fields
        $required = ['title', 'author', 'isbn', 'publicationDate', 'genre', 'location', 'totalCopies', 'availableCopies', 'providerID'];
        foreach ($required as $field) {
            if (!isset($data[$field]) || empty(trim($data[$field]))) {
                http_response_code(400);
                echo json_encode(['message' => "Missing required field: $field."]);
                exit();
            }
        }
        $result = addBook(
            $conn, 
            $data['title'], 
            $data['author'], 
            $data['isbn'], 
            $data['publicationDate'], 
            $data['genre'], 
            $data['location'], 
            $data['totalCopies'], 
            $data['availableCopies'], 
            $data['providerID']
        );
        echo json_encode(['success' => $result]);
        break;
    
    case 'update':
        // TODO: Implement authentication if needed
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) {
            http_response_code(400);
            echo json_encode(['message' => 'Invalid JSON.']);
            exit();
        }
        // Validate required fields
        $required = ['bookID', 'title', 'author', 'isbn', 'publicationDate', 'genre', 'location', 'totalCopies', 'availableCopies', 'providerID'];
        foreach ($required as $field) {
            if (!isset($data[$field]) || empty(trim($data[$field]))) {
                http_response_code(400);
                echo json_encode(['message' => "Missing required field: $field."]);
                exit();
            }
        }
        $result = updateBook(
            $conn, 
            $data['bookID'], 
            $data['title'], 
            $data['author'], 
            $data['isbn'], 
            $data['publicationDate'], 
            $data['genre'], 
            $data['location'], 
            $data['totalCopies'], 
            $data['availableCopies'], 
            $data['providerID']
        );
        echo json_encode(['success' => $result]);
        break;
    
    case 'archive':
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data || !isset($data['bookID'])) {
            http_response_code(400);
            echo json_encode(['message' => 'Missing required field: bookID.']);
            exit();
        }
        $result = archiveBook($conn, $data['bookID']);
        echo json_encode(['success' => $result]);
        break;
    
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
        break;
}
?>
