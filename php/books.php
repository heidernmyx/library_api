<?php

header('Content-Type: application/json'); 
header('Access-Control-Allow-Origin: *'); 

include '../php/connection/connection.php';

class Book {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    // **Add a New Book**
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
            
            // Insert the new book
            $stmt = $this->pdo->prepare("INSERT INTO books (Title, AuthorID, ISBN, PublicationDate, ProviderID) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$json['title'], $authorId, $json['isbn'], $json['publication_date'], $json['provider_id']]);
            
            $bookId = $this->pdo->lastInsertId();

            // Handle genres
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
        // Remove all existing genre associations for this book
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

    // **Reserve a Book**
    public function reserveBook($userId, $bookId) {
        try {
            $this->pdo->beginTransaction();

            // Check if the user has already reserved this book
            $stmt = $this->pdo->prepare("SELECT * FROM reservations WHERE UserID = ? AND BookID = ? AND StatusID IN (1, 6)");
            $stmt->execute([$userId, $bookId]);
            $existingReservation = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existingReservation) {
                $this->pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'You have already reserved this book.']);
                return;
            }

            // Insert reservation without modifying copy availability
            $stmt = $this->pdo->prepare("INSERT INTO reservations (UserID, BookID, ReservationDate, ExpirationDate, StatusID) VALUES (?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 7 DAY), 5)");
            $stmt->execute([$userId, $bookId]);

            // Optionally, notify the librarian here (not implemented)

            $this->pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Book reserved successfully.']);
        } catch (\PDOException $e) {
            $this->pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to reserve book.', 'error' => $e->getMessage()]);
        }
    }

    public function borrowBook($userId, $reservationId) {
    try {
        $this->pdo->beginTransaction();

        // Fetch reservation details
        $stmt = $this->pdo->prepare("SELECT BookID FROM reservations WHERE ReservationID = ? AND StatusID = 6");
        $stmt->execute([$reservationId]);
        $reservation = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($reservation) {
            $bookId = $reservation['BookID'];

            // Fetch an available copy
            $stmt = $this->pdo->prepare("SELECT CopyID FROM book_copies WHERE BookID = ? AND IsAvailable = 1 LIMIT 1");
            $stmt->execute([$bookId]);
            $availableCopy = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($availableCopy) {
                $copyId = $availableCopy['CopyID'];

                // Update copy availability
                $stmt = $this->pdo->prepare("UPDATE book_copies SET IsAvailable = 0 WHERE CopyID = ?");
                $stmt->execute([$copyId]);

                // Update reservation status to 'Fulfilled' (StatusID = 2)
                $stmt = $this->pdo->prepare("UPDATE reservations SET StatusID = 2 WHERE ReservationID = ?");
                $stmt->execute([$reservationId]);

                // Insert into borrowed_books
                $stmt = $this->pdo->prepare("
                    INSERT INTO borrowed_books (UserID, BookID, CopyID, BorrowDate, DueDate, StatusID, PenaltyFees)
                    VALUES (?, ?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 14 DAY), 4, 0)
                ");
                $stmt->execute([$userId, $bookId, $copyId]);

                $this->pdo->commit();

                echo json_encode(['success' => true, 'message' => 'Book borrowed successfully.']);
            } else {
                $this->pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'No available copies to borrow.']);
            }
        } else {
            $this->pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Invalid reservation or already fulfilled.']);
        }
    } catch (\PDOException $e) {
        $this->pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to borrow book.', 'error' => $e->getMessage()]);
    }
}

    public function returnBook($userId, $bookId) {
        try {
            $this->pdo->beginTransaction();

            // Find the borrowed book record
            $stmt = $this->pdo->prepare("SELECT * FROM borrowed_books WHERE UserID = ? AND BookID = ? AND StatusID = 4");
            $stmt->execute([$userId, $bookId]);
            $borrowedBook = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($borrowedBook) {
                $borrowId = $borrowedBook['BorrowID'];
                $copyId = $borrowedBook['CopyID'];

                // Update borrowed_books record
                $returnDate = date('Y-m-d');
                $dueDate = $borrowedBook['DueDate'];
                $penaltyFees = 0;

                // Calculate penalty fees if any
                if ($returnDate > $dueDate) {
                    $lateDays = ceil((strtotime($returnDate) - strtotime($dueDate)) / (60 * 60 * 24));
                    $penaltyFees = $lateDays * 1; // Assuming $1 per day
                }

                $stmt = $this->pdo->prepare("UPDATE borrowed_books SET ReturnDate = ?, PenaltyFees = ?, StatusID = 2 WHERE BorrowID = ?");
                $stmt->execute([$returnDate, $penaltyFees, $borrowId]);

                // Update copy availability
                $stmt = $this->pdo->prepare("UPDATE book_copies SET IsAvailable = 1 WHERE CopyID = ?");
                $stmt->execute([$copyId]);

                $this->pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Book returned successfully.', 'penalty_fees' => $penaltyFees]);
            } else {
                $this->pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'No borrowed record found.']);
            }
        } catch (\PDOException $e) {
            $this->pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to return book.', 'error' => $e->getMessage()]);
        }
    }

public function fetchReturnedBooks() {
    try {
        // Fetch borrowed books with StatusID indicating returned
        $stmt = $this->pdo->prepare("
            SELECT
                bb.BorrowID,
                u.Name AS UserName,
                b.BookID,
                b.Title,
                a.AuthorName,
                bb.BorrowDate,
                bb.DueDate,
                bb.ReturnDate,
                rs.StatusName AS BorrowStatus,
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
                bb.StatusID = 2 -- Assuming 2 represents 'Returned'
            ORDER BY
                bb.ReturnDate DESC
        ");
        $stmt->execute();

        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'returned_books' => $data]);
    } catch (\PDOException $e) {
        http_response_code(500); // Internal Server Error
        echo json_encode([
            'success' => false,
            'message' => 'Failed to fetch returned books.',
            'error' => $e->getMessage()
        ]);
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
                COUNT(book_copies.CopyID) AS TotalCopies,
                SUM(CASE WHEN book_copies.IsAvailable = 1 THEN 1 ELSE 0 END) AS AvailableCopies,
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
                books.BookID"
        );
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($data);
    }

    // **Fetch Borrowed Books**
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
            ";

            // Modify query if userId is provided
            if ($userId !== null) {
                $sql .= " WHERE bb.UserID = ? ORDER BY bb.BorrowDate DESC";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([$userId]);
            } else {
                $sql .= " ORDER BY bb.BorrowDate DESC";
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

    // **Fetch Reserved Books**
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
                    users ON reservations.UserID = users.UserID
                WHERE 
                    reservations.StatusID != 2
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

    // **Update Reservation Status**
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

    // **Fetch Genres**
    public function fetchGenres() {
        $stmt = $this->pdo->prepare("SELECT * FROM genres");
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($data);
    }

    // **Fetch Reservation Statuses**
    public function fetchStatus() {
        $stmt = $this->pdo->prepare("SELECT * FROM reservation_status");
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($data);
    }

public function confirmReturn($borrowId) {
    try {
        $this->pdo->beginTransaction();

        // Fetch borrowed book details
        $stmt = $this->pdo->prepare("SELECT CopyID FROM borrowed_books WHERE BorrowID = ? AND StatusID = 2");
        $stmt->execute([$borrowId]);
        $borrowedBook = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($borrowedBook) {
            $copyId = $borrowedBook['CopyID'];

            // Update book copy to be available
            $stmt = $this->pdo->prepare("UPDATE book_copies SET IsAvailable = 1 WHERE CopyID = ?");
            $stmt->execute([$copyId]);

            // Update borrowed_books StatusID to 3 (Confirmed)
            $stmt = $this->pdo->prepare("UPDATE borrowed_books SET StatusID = 3 WHERE BorrowID = ?");
            $stmt->execute([$borrowId]);

            $this->pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Return confirmed and book is now available for borrowing.']);
        } else {
            $this->pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Borrowed book not found or already confirmed.']);
        }
    } catch (\PDOException $e) {
        $this->pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to confirm return.', 'error' => $e->getMessage()]);
    }
}


    // **Update Book Details**
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
        // Delete all existing copies
        $stmt = $this->pdo->prepare("DELETE FROM book_copies WHERE BookID = ?");
        $stmt->execute([$bookId]);

        // Add the new number of copies
        for ($i = 1; $i <= $newCopies; $i++) {
            $stmt = $this->pdo->prepare("INSERT INTO book_copies (BookID, CopyNumber, IsAvailable) VALUES (?, ?, ?)");
            $stmt->execute([$bookId, $i, 1]); // Set IsAvailable to 1 for new copies
        }
    }

    private function validateUpdateInput($json) {
        return !empty($json['book_id']) && !empty($json['title']) && !empty($json['author']) && 
               isset($json['genres']) && is_array($json['genres']) && 
               !empty($json['isbn']) && !empty($json['publication_date']) &&
               !empty($json['provider_id']);
    }
}

// **Handle Incoming Requests**
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
    case 'fetchReturnedBooks':
        $book->fetchReturnedBooks();
        break;
     case 'confirmReturn':
        $book->confirmReturn($json['borrow_id']);
        break;
    case 'reserveBook':
        $book->reserveBook($json['user_id'], $json['book_id']);
        break;
    case 'borrowBook':
        $book->borrowBook($json['user_id'], $json['reservation_id']);
        break; 
    case 'returnBook':
        $book->returnBook($json['user_id'], $json['book_id']);
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

?>
