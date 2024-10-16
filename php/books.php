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
        
        // Use $json['provider_id'] instead of $providerId
        $stmt = $this->pdo->prepare("INSERT INTO books (Title, AuthorID, ISBN, PublicationDate, ProviderID) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$json['title'], $authorId, $json['isbn'], $json['publication_date'], $json['provider_id']]);
        
        $bookId = $this->pdo->lastInsertId();

        // Handle genres (assume it's an array)
        $this->handleGenres($json['genres'], $bookId);

        // Add book copies
        if (isset($json['copies']) && is_numeric($json['copies'])) {
            $this->addBookCopies($bookId, intval($json['copies']));
        }

        $this->pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Book added successfully.', 'book_id' => $bookId]);
    } catch (\PDOException $e) {
        $this->pdo->rollBack();
        http_response_code(500); // Internal Server Error
        echo json_encode(['success' => false, 'message' => 'Failed to add book.', 'error' => $e->getMessage()]);
    }
}

private function addBookCopies($bookId, $copies) {
    for ($i = 1; $i <= $copies; $i++) {
        $stmt = $this->pdo->prepare("INSERT INTO book_copies (BookID, CopyNumber, IsAvailable) VALUES (?, ?, ?)");
        $stmt->execute([$bookId, $i, 1]); // Set IsAvailable to 1 for new copies
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
        // First, remove all existing genre associations for this book
        $stmt = $this->pdo->prepare("DELETE FROM books_genre WHERE BookId = ?");
        $stmt->execute([$bookId]);

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


public function reserveBook($userId, $bookId) {
    try {
     
        $this->pdo->beginTransaction();

        //  Fetch an available copy of the book
        $stmt = $this->pdo->prepare("SELECT CopyNumber FROM book_copies WHERE BookID = ? AND IsAvailable = 1 LIMIT 1");
        $stmt->execute([$bookId]);
        $availableCopy = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($availableCopy) {
            //  Update the copy to set IsAvailable = 0
            $stmt = $this->pdo->prepare("UPDATE book_copies SET IsAvailable = 0 WHERE BookID = ? AND CopyNumber = ?");
            $stmt->execute([$bookId, $availableCopy['CopyNumber']]);

    
            $stmt = $this->pdo->prepare("INSERT INTO reservations (UserID, BookID, ReservationDate, ExpirationDate, StatusID) VALUES (?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 7 DAY), 1)");
            $stmt->execute([$userId, $bookId]);

            //! Commit the transaction
            $this->pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Book reserved successfully.']);
        } else {
            // Rollback if no available copies
            $this->pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'No copies available.']);
        }
    } catch (\PDOException $e) {
        $this->pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to reserve book.', 'error' => $e->getMessage()]);
    }
}


public function borrowBook($userId, $reservationId) {
    try {
        $stmt = $this->pdo->prepare("SELECT BookID FROM reservations WHERE ReservationID = ? AND StatusID = 6");
        $stmt->execute([$reservationId]);
        $reservation = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($reservation) {
            $bookId = $reservation['BookID'];

            // Make one copy as unavailable
            $stmt = $this->pdo->prepare("UPDATE book_copies SET IsAvailable = 0 WHERE BookID = ? AND IsAvailable = 1 LIMIT 1");
            $stmt->execute([$bookId]);

            // Update reservation status to 'Fulfilled'
            $stmt = $this->pdo->prepare("UPDATE reservations SET StatusID = 2 WHERE ReservationID = ?");
            $stmt->execute([$reservationId]);

            // Log the borrowing action (you might need a borrowing table for tracking)
        $stmt = $this->pdo->prepare("
    INSERT INTO borrowed_books (UserID, BookID, BorrowDate, DueDate) 
    VALUES (?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 5 DAY))
");

            $stmt->execute([$userId, $bookId]);

            echo json_encode(['success' => true, 'message' => 'Book borrowed successfully.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid reservation or already fulfilled.']);
        }
    } catch (\PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to borrow book.', 'error' => $e->getMessage()]);
    }
}

  public function fetchBooks() {
    $stmt = $this->pdo->prepare(
    "SELECT
    books.BookID,
    books.Title,
    authors.AuthorName,
    books.ISBN,
    books.PublicationDate,
    book_providers.ProviderName,
    COUNT(book_copies.CopyNumber) AS TotalCopies,
    SUM(
        CASE WHEN book_copies.IsAvailable = 1 THEN 1 ELSE 0
    END
) AS AvailableCopies,
(
    SELECT
        GROUP_CONCAT(genres.GenreName SEPARATOR ', ')
    FROM
        books_genre
    LEFT JOIN genres ON books_genre.GenreID = genres.GenreID
    WHERE
        books_genre.BookID = books.BookID
) AS Genres
FROM
    books
LEFT JOIN authors ON books.AuthorID = authors.AuthorID
LEFT JOIN book_providers ON books.ProviderID = book_providers.ProviderID
LEFT JOIN book_copies ON books.BookID = book_copies.BookID
GROUP BY
    books.BookID");
    $stmt->execute();
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($data);
}
public function fetchBorrowedBooks($userId = null) {
    try {
        // Base query
        $sql = "
         SELECT
    bb.BorrowID,
    u.Name,
    b.BookID,
    b.Title,
    a.AuthorName,
    bb.BorrowDate,
    bb.DueDate,
    bb.ReturnDate,
    rs.StatusName,
    b.ISBN,
    b.PublicationDate,
    bp.ProviderName,
    bb.PenaltyFees
FROM
    borrowed_books bb
JOIN users u ON
    bb.UserID = u.UserID
JOIN books b ON
    bb.BookID = b.BookID
LEFT JOIN authors a ON
    b.AuthorID = a.AuthorID
LEFT JOIN book_providers bp ON
    b.ProviderID = bp.ProviderID
LEFT JOIN reservation_status rs ON
    bb.StatusID = rs.StatusID
WHERE
    bb.StatusID != 2 -- You can modify this condition based on your requirements
ORDER BY
    bb.BorrowDate
DESC
    ;

           
        ";

        // Modify query if userId is provided
        if ($userId !== null) {
            // If userId is provided, filter results by user
            $sql .= " AND reservations.UserID = ? ORDER BY reservations.ReservationDate DESC";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$userId]);
        } else {
            // If userId is null, fetch all borrowed books
            $sql .= " ORDER BY reservations.ReservationDate DESC";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
        }

        // Fetch data
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Return the result
        echo json_encode(['success' => true, 'borrowed_books' => $data]);
    } catch (\PDOException $e) {
        // Handle errors
        http_response_code(500); // Internal Server Error
        echo json_encode(['success' => false, 'message' => 'Failed to fetch borrowed books.', 'error' => $e->getMessage()]);
    }
}


public function fetchReservedBooks($userId = null) {
    try {
        // Base query
        $sql = "
              SELECT 
                reservations.ReservationID,
                users.Name,
                books.BookID,
                books.Title,
                authors.AuthorName,
                reservations.ReservationDate,
                reservations.ExpirationDate,
                reservation_status.StatusName,
                books.ISBN,
                books.PublicationDate,
                book_providers.ProviderName
            FROM 
                reservations
            LEFT JOIN 
                books ON reservations.BookID = books.BookID
            LEFT JOIN 
                authors ON books.AuthorID = authors.AuthorID
            LEFT JOIN 
                book_providers ON books.ProviderID = book_providers.ProviderID
            LEFT JOIN 
                reservation_status ON reservations.StatusID = reservation_status.StatusID
            JOIN
            	users ON reservations.UserID=users.UserID
            WHERE 
                reservations.StatusID != 2 -- Exclude reservations with StatusID = 2
        ";

        // Modify query if userId is provided
        if ($userId !== null) {
            // If userId is provided, filter results by user
            $sql .= " AND reservations.UserID = ? ORDER BY reservations.ReservationDate DESC";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$userId]);
        } else {
            // If userId is null, fetch all reserved books
            $sql .= " ORDER BY reservations.ReservationDate DESC";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
        }

        // Fetch data
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Return the result
        echo json_encode(['success' => true, 'reserved_books' => $data]);
    } catch (\PDOException $e) {
        // Handle errors
        http_response_code(500); // Internal Server Error
        echo json_encode(['success' => false, 'message' => 'Failed to fetch reserved books.', 'error' => $e->getMessage()]);
    }
}


public function updateReservationStatus($reservation_id, $status_id){
    try{
        $stmt = $this->pdo->prepare("UPDATE reservations SET StatusID = ? WHERE ReservationID = ?");
        $stmt->execute([$status_id, $reservation_id]);
        echo json_encode(['success' => true, 'message' => 'Reservation status updated successfully.']);
    } catch (\PDOException $e) {
        http_response_code(500); // Internal Server Error
        echo json_encode(['success' => false, 'message' => 'Failed to update reservation status.', 'error' => $e->getMessage()]);

    }

}

public function fetchGenres() {
        $stmt = $this->pdo->prepare("SELECT * FROM genres");
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($data);
    }




public function fetchStatus() {
    $stmt = $this->pdo->prepare("SELECT * FROM reservation_status");
    $stmt->execute();
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($data);
}

public function updateBooks($json) {
    if (empty($json['book_id'])) {
        http_response_code(400); // Bad Request
        echo json_encode(['success' => false, 'message' => 'Book ID is required.']);
        return;
    }

    try {
        $this->pdo->beginTransaction();

        // Get author ID
        $authorId = $this->getOrCreateAuthor($json['author']);

        // Update book details
        $stmt = $this->pdo->prepare("UPDATE books SET Title = ?, AuthorID = ?, ISBN = ?, PublicationDate = ?, ProviderID = ? WHERE BookID = ?");
        $stmt->execute([$json['title'], $authorId, $json['isbn'], $json['publication_date'], $json['provider_id'], $json['book_id']]);

        // Update genres
        $this->handleGenres($json['genres'], $json['book_id']);

        // Update copies
        if (isset($json['copies']) && is_numeric($json['copies'])) {
            $this->updateBookCopies($json['book_id'], intval($json['copies']));
        }

        $this->pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Book updated successfully.']);
    } catch (\PDOException $e) {
        $this->pdo->rollBack();
        http_response_code(500); // Internal Server Error
        echo json_encode(['success' => false, 'message' => 'Failed to update book.', 'error' => $e->getMessage()]);
    }
}

private function updateBookCopies($bookId, $newCopies) {
    // Check if any copies exist for the book
    $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM book_copies WHERE BookID = ?");
    $stmt->execute([$bookId]);
    $copyCount = $stmt->fetchColumn();

    if ($copyCount > 0) {
        // If copies exist, delete all existing copies first
        $stmt = $this->pdo->prepare("DELETE FROM book_copies WHERE BookID = ?");
        $stmt->execute([$bookId]);
    }

    // Add the new number of copies
    for ($i = 1; $i <= $newCopies; $i++) {
        $stmt = $this->pdo->prepare("INSERT INTO book_copies (BookID, CopyNumber, IsAvailable) VALUES (?, ?, ?)");
        $stmt->execute([$bookId, $i, 1]); // Set IsAvailable to 1 for new copies
    }
}



    private function validateUpdateInput($json) {
        return !empty($json['id']) && !empty($json['title']) && !empty($json['author']) && 
               isset($json['genres']) && is_array($json['genres']) && 
               !empty($json['isbn']) && !empty($json['publication_date']) &&
               !empty($json['provider_id']);
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
    case 'fetchBooks':
        $book->fetchBooks();
        break;
    case 'updateBook':
        $book->updateBooks($json);
        break;
    case 'fetchGenres':
        $book->fetchGenres();
        break;
    case 'fetchReservedBooks':
        if (isset($json['user_id'])) {
        $book->fetchReservedBooks($json['user_id']);
    } else {
        $book->fetchReservedBooks(); // Call with no userId
    }
    break;
    case 'fetchStatus':
        $book->fetchStatus();
        break;
    case 'reserveBook':
        $book->reserveBook($json['user_id'], $json['book_id']);
        break;
    case 'borrowBook':
        $book->borrowBook($json['user_id'], $json['reservation_id']);
        break; 
    case 'fetchBorrowedBooks':
        if (isset($json['user_id'])) {
            $book->fetchBorrowedBooks($json['user_id']);
        } else {
            $book->fetchBorrowedBooks(); // Call with no userId
        }
        break;
    case 'updateReservationStatus':
        $book->updateReservationStatus($json['reservation_id'], $json['status_id']);
        break;
    default:
        http_response_code(400); // Bad Request
        echo json_encode(['success' => false, 'message' => 'Invalid operation.']);
        break;
}