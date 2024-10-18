<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With');

// Include the database connection and Notification class
include '../php/connection/connection.php'; // Ensure the path is correct
require_once 'notification.php'; // Include the Notification class
// require_once 'bookpublisher.php';
class Book {
    private $pdo;
    private $notification;
    // private $bookpublisher;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->notification = new Notification($pdo); // Initialize Notification class
        // $this->bookpublisher = new BookPublisher($pdo);
    }

    /**
     * **Add a New Book**
     * Adds a new book to the library.
     *
     * @param array $json
     */
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
            $stmt = $this->pdo->prepare("
                INSERT INTO books (Title, AuthorID, ISBN, PublicationDate, ProviderID)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $json['title'], 
                $authorId, 
                $json['isbn'], 
                $json['publication_date'], 
                $json['provider_id']
            ]);

            $bookId = $this->pdo->lastInsertId();

            // Handle genres
            $this->handleGenres($json['genres'], $bookId);

            // Add book copies
            if (isset($json['copies']) && is_numeric($json['copies'])) {
                $this->addBookCopies($bookId, intval($json['copies']));
            }

            $this->pdo->commit();

            // **Add notification for librarians about new book**
            $message = "A new book '{$json['title']}' has been added to the library.";
            $notificationTypeId = 9; // 9 = 'New Book Added'
            $this->notification->addNotificationForLibrarians($message, $notificationTypeId);
            $this->notification->addNotification(null, "A new book '{$json['title']}' has been added to the library.", 9);


            echo json_encode(['success' => true, 'message' => 'Book added successfully.', 'book_id' => $bookId]);
        } catch (\PDOException $e) {
            $this->pdo->rollBack();
            http_response_code(500); // Internal Server Error
            echo json_encode(['success' => false, 'message' => 'Failed to add book.', 'error' => $e->getMessage()]);
        }
    }

    /**
     * **Add Book Copies**
     * Adds the specified number of copies for a book.
     *
     * @param int $bookId
     * @param int $copies
     */
    private function addBookCopies($bookId, $copies) {
        for ($i = 1; $i <= $copies; $i++) {
            $stmt = $this->pdo->prepare("
                INSERT INTO book_copies (BookID, CopyNumber, IsAvailable)
                VALUES (?, ?, 1)
            ");
            $stmt->execute([$bookId, $i]);
            $this->notification->addNotificationForLibrarians("A new copy of Book ID: $bookId has been added.", 17); // 10 = 'New Copy Added'
                echo json_encode(['success' => true, 'message' => 'Book copies added successfully.', 'book_id' => $bookId]);
        }
    }

    /**
     * **Validate Book Input**
     * Validates the input data for adding/updating a book.
     *
     * @param array $json
     * @return bool
     */
    private function validateBookInput($json) {
        return !empty($json['title']) && 
               !empty($json['author']) &&
               isset($json['genres']) && is_array($json['genres']) &&
               !empty($json['isbn']) && 
               !empty($json['publication_date']) &&
               !empty($json['provider_id']);
    }

    /**
     * **Get or Create Author**
     * Retrieves the AuthorID if the author exists, or creates a new author.
     *
     * @param string $authorName
     * @return int
     */
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
            $this->notification->addNotificationForLibrarians("A new author '$authorName' has been added.", 18); // 16 = 'New Author Added'
            return $this->pdo->lastInsertId();
        }
    }

    /**
     * **Handle Genres**
     * Manages the association between books and genres.
     *
     * @param array $genres
     * @param int $bookId
     */
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

    /**
     * **Reserve a Book**
     * Allows a user to reserve a book.
     *
     * @param int $userId
     * @param int $bookId
     */
    public function reserveBook($userId, $bookId) {
        try {
            $this->pdo->beginTransaction();

            // Check if the user has already reserved this book
            $stmt = $this->pdo->prepare("
                SELECT * FROM reservations 
                WHERE UserID = ? AND BookID = ? AND StatusID IN (1, 5, 6)
            ");
            $stmt->execute([$userId, $bookId]);
            $existingReservation = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existingReservation) {
                $this->pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'You have already reserved this book.']);
                return;
            }

            // Insert reservation without modifying copy availability
            $stmt = $this->pdo->prepare("
                INSERT INTO reservations (UserID, BookID, ReservationDate, ExpirationDate, StatusID)
                VALUES (?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 7 DAY), 5)
            ");
            $stmt->execute([$userId, $bookId]);

            // **Add notification for the librarian**
            $bookTitle = $this->getBookTitle($bookId);
            $message = "A new reservation has been made for '{$bookTitle}' by User ID: $userId. Please review the reservation";
            $notificationTypeId = 1; // 1 = 'Reservation Made'
            $this->notification->addNotificationForLibrarians($message, $notificationTypeId);

            $this->pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Book reserved successfully.']);
        } catch (\PDOException $e) {
            $this->pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to reserve book.', 'error' => $e->getMessage()]);
        }
    }

    /**
     * **Borrow a Book**
     * Facilitates the borrowing of a book based on a reservation.
     *
     * @param int $userId
     * @param int $reservationId
     */
    public function borrowBook($userId, $reservationId) {
        try {
            $this->pdo->beginTransaction();

            // Fetch reservation details
            $stmt = $this->pdo->prepare("
                SELECT BookID FROM reservations 
                WHERE ReservationID = ? AND StatusID = 6
            ");
            $stmt->execute([$reservationId]);
            $reservation = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($reservation) {
                $bookId = $reservation['BookID'];

                // Fetch an available copy
                $stmt = $this->pdo->prepare("
                    SELECT CopyID FROM book_copies 
                    WHERE BookID = ? AND IsAvailable = 1 LIMIT 1
                ");
                $stmt->execute([$bookId]);
                $availableCopy = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($availableCopy) {
                    $copyId = $availableCopy['CopyID'];

                    // Update copy availability
                    $stmt = $this->pdo->prepare("
                        UPDATE book_copies SET IsAvailable = 0 WHERE CopyID = ?
                    ");
                    $stmt->execute([$copyId]);

                    // Update reservation status to 'Fulfilled' (StatusID = 2)
                    $stmt = $this->pdo->prepare("
                        UPDATE reservations SET StatusID = 2 WHERE ReservationID = ?
                    ");
                    $stmt->execute([$reservationId]);

                    // Insert into borrowed_books
                    $stmt = $this->pdo->prepare("
                        INSERT INTO borrowed_books (UserID, BookID, CopyID, BorrowDate, DueDate, StatusID, PenaltyFees)
                        VALUES (?, ?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 14 DAY), 4, 0)
                    ");
                    $stmt->execute([$userId, $bookId, $copyId]);


                    $dueDate = date('Y-m-d', strtotime('+14 days'));
                    $bookTitle = $this->getBookTitle($bookId);
                    $message = "You have successfully borrowed '{$bookTitle}'. Due date is $dueDate.";
                    $notificationTypeId = 2; // 2 = 'Book Borrowed'
                    $userName = $this->getUsersName($userId);
                    $this->notification->addNotification($userId, $message, $notificationTypeId);
                    $this->notification->addNotificationForLibrarians("$userName has borrowed '{$bookTitle}'.", 2); // 2 = 'Book Borrowed'
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

    /**
     * **Get Book Title**
     * Helper function to retrieve the book title by BookID.
     *
     * @param int $bookId
     * @return string
     */
    private function getBookTitle($bookId) {
        $stmt = $this->pdo->prepare("SELECT Title FROM books WHERE BookID = ?");
        $stmt->execute([$bookId]);
        $book = $stmt->fetch(PDO::FETCH_ASSOC);
        return $book ? $book['Title'] : 'Unknown Title';
    }
    private function getUsersName($userId){
        $stmt = $this->pdo->prepare("SELECT Name FROM users WHERE UserId = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user ? $user['Name'] : 'Unknown User';
    }
    /**
     * **Return a Book**
     * Handles the return of a borrowed book.
     *
     * @param int $userId
     * @param int $borrowId
     */
    public function returnBook($userId, $borrowId) {
        try {
            $this->pdo->beginTransaction();

            // Find the borrowed book record
            $stmt = $this->pdo->prepare("
                SELECT * FROM borrowed_books 
                WHERE BorrowID = ? AND UserID = ? AND StatusID = 4
            ");
            $stmt->execute([$borrowId, $userId]);
            $borrowedBook = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($borrowedBook) {
                $copyId = $borrowedBook['CopyID'];
                $bookId = $borrowedBook['BookID'];

                // Calculate penalty fees if any
                $returnDate = date('Y-m-d');
                $dueDate = $borrowedBook['DueDate'];
                $penaltyFees = 0;

                if ($returnDate > $dueDate) {
                    $lateDays = ceil((strtotime($returnDate) - strtotime($dueDate)) / (60 * 60 * 24));
                    $penaltyFees = $lateDays * 1; // Assuming $1 per day
                }

                // Update borrowed_books record
                $stmt = $this->pdo->prepare("
                    UPDATE borrowed_books 
                    SET ReturnDate = ?, PenaltyFees = ?, StatusID = 2 
                    WHERE BorrowID = ?
                ");
                $stmt->execute([$returnDate, $penaltyFees, $borrowId]);

                // **Add notification for the librarian**
                $bookTitle = $this->getBookTitle($bookId);
                $userName = $this->getUsersName($userId);
                $message = " $userName has returned '{$bookTitle}'. Please confirm the return.";
                $notificationTypeId = 3; // 3 = 'Book Returned'
                $this->notification->addNotificationForLibrarians($message, $notificationTypeId);

                // **Notify user if penalty fees are applied**
                if ($penaltyFees > 0) {
                    $message = "You have been charged a penalty fee of \$$penaltyFees for the late return of '{$bookTitle}'.";
                    $notificationTypeId = 8; // 8 = 'Penalty Fees Applied'
                    $this->notification->addNotification($userId, $message, $notificationTypeId);
                }

                $this->pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Book returned successfully.', 'penalty_fees' => $penaltyFees]);
            } else {
                $this->pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'No borrowed record found or book already returned.']);
            }
        } catch (\PDOException $e) {
            $this->pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to return book.', 'error' => $e->getMessage()]);
        }
    }

    /**
     * **Confirm Return**
     * Confirms the return of a book by updating the copy's availability and notifying the user.
     *
     * @param int $borrowId
     */
    public function confirmReturn($borrowId) {
        try {
            $this->pdo->beginTransaction();

            // Fetch borrowed book details
            $stmt = $this->pdo->prepare("
                SELECT CopyID, UserID, BookID 
                FROM borrowed_books 
                WHERE BorrowID = ? AND StatusID = 2
            ");
            $stmt->execute([$borrowId]);
            $borrowedBook = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($borrowedBook) {
                $copyId = $borrowedBook['CopyID'];
                $userId = $borrowedBook['UserID'];
                $bookId = $borrowedBook['BookID'];

                // Update book copy to be available
                $stmt = $this->pdo->prepare("
                    UPDATE book_copies SET IsAvailable = 1 WHERE CopyID = ?
                ");
                $stmt->execute([$copyId]);

                // Update borrowed_books StatusID to 3 (Confirmed)
                $stmt = $this->pdo->prepare("
                    UPDATE borrowed_books SET StatusID = 3 WHERE BorrowID = ?
                ");
                $stmt->execute([$borrowId]);

                // **Add notification for the user**
                $bookTitle = $this->getBookTitle($bookId);
                $message = "Your return for '{$bookTitle}' has been confirmed. Thank you!";
                $notificationTypeId = 4; // 4 = 'Return Confirmed'
                $this->notification->addNotification($userId, $message, $notificationTypeId);

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

    /**
     * **Fetch Returned Books**
     * Retrieves returned books with StatusID indicating 'Returned' (2).
     */
    public function fetchReturnedBooks() {
        try {
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
                    nt.NotificationTypeName AS BorrowStatus,
                    b.ISBN,
                    b.PublicationDate,
                    bp.ProviderName,
                    bb.PenaltyFees
                FROM
                    borrowed_books bb
                JOIN users u ON bb.UserID = u.UserID
                JOIN books b ON bb.BookID = b.BookID
                LEFT JOIN authors a ON b.AuthorID = a.AuthorID
                LEFT JOIN book_providers bp ON b.ProviderID = bp.ProviderID
                LEFT JOIN notification_type nt ON bb.StatusID = nt.NotificationTypeID
                WHERE
                    bb.StatusID = 2 -- 'Returned'
                ORDER BY
                    bb.ReturnDate DESC
            ");
            $stmt->execute();

            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'returned_books' => $data]);
        } catch (\PDOException $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to fetch returned books.',
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * **Fetch Books**
     * Retrieves all books with their details.
     */
    public function fetchBooks() {
        try {
            $stmt = $this->pdo->prepare("
              SELECT
                books.BookID,
                books.Title,
                authors.AuthorName,
                publisher.PublisherName,
                books.ISBN,
                books.PublicationDate,
                book_providers.ProviderName,
                COUNT(DISTINCT book_copies.CopyID) AS TotalCopies,
                SUM(
                    CASE WHEN book_copies.IsAvailable = 1 THEN 1 ELSE 0
                END
            ) AS AvailableCopies,
            GROUP_CONCAT(
                DISTINCT genres.GenreName SEPARATOR ', '
            ) AS Genres
            FROM
                books
            LEFT JOIN authors ON books.AuthorID = authors.AuthorID
            LEFT JOIN book_providers ON books.ProviderID = book_providers.ProviderID
            LEFT JOIN book_copies ON books.BookID = book_copies.BookID
            LEFT JOIN books_genre ON books.BookID = books_genre.BookID
            LEFT JOIN genres ON books_genre.GenreID = genres.GenreID
            LEFT JOIN publisher ON books.PublisherID = publisher.PublisherID
            GROUP BY
                books.BookID
            ORDER BY
                books.Title ASC;

            ");
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'books' => $data]);
        } catch (\PDOException $e) {
            http_response_code(500); // Internal Server Error
            echo json_encode([
                'success' => false,
                'message' => 'Failed to fetch books.',
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * **Fetch Borrowed Books**
     * Retrieves borrowed books, optionally filtered by user.
     *
     * @param int|null $userId
     */
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
                    rs.StatusName AS BorrowStatus,
                    nt.NotificationTypeName AS StatusName,
                    b.ISBN,
                    b.PublicationDate,
                    bp.ProviderName,
                    bb.PenaltyFees
                FROM
                    borrowed_books bb
                JOIN users u ON bb.UserID = u.UserID
                JOIN books b ON bb.BookID = b.BookID
                JOIN reservation_status rs ON bb.StatusID = rs.StatusID
                LEFT JOIN authors a ON b.AuthorID = a.AuthorID
                LEFT JOIN book_providers bp ON b.ProviderID = bp.ProviderID
                LEFT JOIN notification_type nt ON bb.StatusID = nt.NotificationTypeID
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
            echo json_encode([
                'success' => false,
                'message' => 'Failed to fetch borrowed books.',
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * **Fetch Reserved Books**
     * Retrieves reserved books, optionally filtered by user.
     *
     * @param int|null $userId
     */
    public function fetchReservedBooks($userId = null) {
        try {
            // Base query
            $sql = "
                SELECT 
                    r.ReservationID,
                    u.Name,
                    b.BookID,
                    b.Title,
                    a.AuthorName,
                    r.ReservationDate,
                    r.ExpirationDate,
                    nt.NotificationTypeName AS StatusName,
                    rs.StatusName AS ReservationStatus,
                    b.ISBN,
                    b.PublicationDate,
                    bp.ProviderName
                FROM 
                    reservations r
                JOIN users u ON r.UserID = u.UserID
                JOIN reservation_status rs ON r.StatusID = rs.StatusID
                LEFT JOIN 
                    books b ON r.BookID = b.BookID
                LEFT JOIN 
                    authors a ON b.AuthorID = a.AuthorID
                LEFT JOIN 
                    book_providers bp ON b.ProviderID = bp.ProviderID
                LEFT JOIN 
                    notification_type nt ON r.StatusID = nt.NotificationTypeID
                WHERE 
                    r.StatusID != 2 -- Assuming 2 = 'Completed'
            ";

            // Modify query if userId is provided
            if ($userId !== null) {
                $sql .= " AND r.UserID = ? ORDER BY r.ReservationDate DESC";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([$userId]);
            } else {
                // If userId is null, fetch all reserved books
                $sql .= " ORDER BY r.ReservationDate DESC";
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
            echo json_encode([
                'success' => false,
                'message' => 'Failed to fetch reserved books.',
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * **Fetch Genres**
     * Retrieves all genres.
     */
    public function fetchGenres() {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM genres ORDER BY GenreName ASC");
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'genres' => $data]);
        } catch (\PDOException $e) {
            http_response_code(500); // Internal Server Error
            echo json_encode([
                'success' => false,
                'message' => 'Failed to fetch genres.',
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * **Fetch Reservation Statuses**
     * Retrieves all reservation statuses.
     */
    public function fetchStatus() {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM reservation_status ORDER BY StatusID ASC");
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'statuses' => $data]);
        } catch (\PDOException $e) {
            http_response_code(500); // Internal Server Error
            echo json_encode([
                'success' => false,
                'message' => 'Failed to fetch reservation statuses.',
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * **Update Book Details**
     * Updates the details of an existing book.
     *
     * @param array $json
     */
    public function updateBooks($json) {
        if (!$this->validateUpdateInput($json)) {
            http_response_code(400); // Bad Request
            echo json_encode(['success' => false, 'message' => 'Invalid input.']);
            return;
        }

        try {
            $this->pdo->beginTransaction();

            // Get author ID
            $authorId = $this->getOrCreateAuthor($json['author']);

            // Update book details
            $stmt = $this->pdo->prepare("
                UPDATE books 
                SET Title = ?, AuthorID = ?, ISBN = ?, PublicationDate = ?, ProviderID = ?
                WHERE BookID = ?
            ");
            $stmt->execute([
                $json['title'], 
                $authorId, 
                $json['isbn'], 
                $json['publication_date'], 
                $json['provider_id'], 
                $json['book_id']
            ]);

            // Update genres
            $this->handleGenres($json['genres'], $json['book_id']);

            // Update copies
            if (isset($json['copies']) && is_numeric($json['copies'])) {
                $this->updateBookCopies($json['book_id'], intval($json['copies']));
            }

            $this->pdo->commit();

            // **Add notification for librarians about book update**
            $message = "The book '{$json['title']}' has been updated.";
            $notificationTypeId = 11; // 11 = 'Book Updated'
            $this->notification->addNotificationForLibrarians($message, $notificationTypeId);

            echo json_encode(['success' => true, 'message' => 'Book updated successfully.']);
        } catch (\PDOException $e) {
            $this->pdo->rollBack();
            http_response_code(500); // Internal Server Error
            echo json_encode(['success' => false, 'message' => 'Failed to update book.', 'error' => $e->getMessage()]);
        }
    }

    /**
     * **Update Book Copies**
     * Updates the number of copies for a book.
     *
     * @param int $bookId
     * @param int $newCopies
     */
    private function updateBookCopies($bookId, $newCopies) {
        // Delete all existing copies
        $stmt = $this->pdo->prepare("DELETE FROM book_copies WHERE BookID = ?");
        $stmt->execute([$bookId]);

        // Add the new number of copies
        for ($i = 1; $i <= $newCopies; $i++) {
            $stmt = $this->pdo->prepare("
                INSERT INTO book_copies (BookID, CopyNumber, IsAvailable)
                VALUES (?, ?, 1)
            ");
            $stmt->execute([$bookId, $i]);
        }
    }

    /**
     * **Validate Update Input**
     * Validates the input data for updating a book.
     *
     * @param array $json
     * @return bool
     */
    private function validateUpdateInput($json) {
        return !empty($json['book_id']) && 
               !empty($json['title']) && 
               !empty($json['author']) && 
               isset($json['genres']) && is_array($json['genres']) && 
               !empty($json['isbn']) && 
               !empty($json['publication_date']) &&
               !empty($json['provider_id']);
    }

    /**
     * **Fetch All Borrowed Books**
     * Retrieves all borrowed books without filtering by user.
     */
    public function fetchAllBorrowedBooks() {
        $this->fetchBorrowedBooks(); // Fetch without userId
    }

    /**
     * **Update Reservation Status**
     * Updates the status of a reservation and sends appropriate notifications.
     *
     * @param int $reservationId
     * @param int $statusId
     */
    public function updateReservationStatus($reservationId, $statusId) {
        try {
            $this->pdo->beginTransaction();

            // Fetch reservation details
            $stmt = $this->pdo->prepare("
                SELECT r.UserID, b.Title 
                FROM reservations r
                JOIN books b ON r.BookID = b.BookID
                WHERE r.ReservationID = ?
            ");
            $stmt->execute([$reservationId]);
            $reservation = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($reservation) {
                // Update reservation status
                $stmt = $this->pdo->prepare("
                    UPDATE reservations 
                    SET StatusID = ? 
                    WHERE ReservationID = ?
                ");
                $stmt->execute([$statusId, $reservationId]);

                // Determine notification based on new status
                switch ($statusId) {
                    case 10: // 'Reservation Canceled'
                        $message = "Your reservation for '{$reservation['Title']}' has been canceled.";
                        $notificationTypeId = 10; // 10 = 'Reservation Canceled'
                        $this->notification->addNotification($reservation['UserID'], $message, $notificationTypeId);
                        break;
                    // Add more cases as needed for different statuses
                    default:
                        // Handle other statuses if necessary
                        break;
                }

                $this->pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Reservation status updated successfully.']);
            } else {
                $this->pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Reservation not found.']);
            }
        } catch (\PDOException $e) {
            $this->pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to update reservation status.', 'error' => $e->getMessage()]);
        }
    }

    /**
     * **Fetch Notifications**
     * Retrieves notifications for a specific user.
     *
     * @param int $userId
     */
    public function fetchNotifications($userId) {
        $notifications = $this->notification->fetchNotifications($userId);
        echo json_encode(['success' => true, 'notifications' => $notifications]);
    }

    /**
     * **Fetch Unread Notifications Count**
     * Retrieves the count of unread notifications for a user.
     *
     * @param int $userId
     */
    public function fetchUnreadCount($userId) {
        $unreadCount = $this->notification->fetchUnreadCount($userId);
        echo json_encode(['success' => true, 'unreadCount' => $unreadCount]);
    }
/**
 * **Mark Notification as Read**
 * Marks a specific notification as read for the authenticated user.
 *
 * @param int $notificationId
 * @param int $userId
 * @return void
 */
public function markNotificationAsRead($notificationId) {
    $success = $this->notification->markNotificationAsRead($notificationId);
    if ($success) {
        echo json_encode(['success' => true, 'message' => 'Notification marked as read.']);
    } else {
      
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Notification not found or already marked as read.']);
    }
}



    /**
     * **Send Overdue Notices**
     * Triggers the sending of overdue notices to users.
     */
    public function sendOverdueNotices() {
        $this->notification->sendOverdueNotices();
        echo json_encode(['success' => true, 'message' => 'Overdue notices sent successfully.']);
    }

    /**
     * **Send Reservation Expiry Reminders**
     * Triggers the sending of reservation expiry reminders to users.
     */
    public function sendReservationExpiryReminders() {
        $this->notification->sendReservationExpiryReminders();
        echo json_encode(['success' => true, 'message' => 'Reservation expiry reminders sent successfully.']);
    }

    /**
     * **Send Due Date Reminders**
     * Triggers the sending of due date reminders to users.
     */
    public function sendDueDateReminders() {
        $this->notification->sendDueDateReminders();
        echo json_encode(['success' => true, 'message' => 'Due date reminders sent successfully.']);
    }
}

// **Handle Incoming Requests**
if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    $operation = isset($_GET['operation']) ? $_GET['operation'] : '';
    $json = isset($_GET['json']) ? json_decode($_GET['json'], true) : null;
}
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $operation = isset($_POST['operation']) ? $_POST['operation'] : '';
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
        if (isset($json['borrow_id'])) {
            $book->confirmReturn($json['borrow_id']);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Borrow ID is required.']);
        }
        break;
    case 'reserveBook':
        if (isset($json['user_id']) && isset($json['book_id'])) {
            $book->reserveBook($json['user_id'], $json['book_id']);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'User ID and Book ID are required.']);
        }
        break;
    case 'borrowBook':
        if (isset($json['user_id']) && isset($json['reservation_id'])) {
            $book->borrowBook($json['user_id'], $json['reservation_id']);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'User ID and Reservation ID are required.']);
        }
        break;
    case 'returnBook':
        if (isset($json['user_id']) && isset($json['borrow_id'])) {
            $book->returnBook($json['user_id'], $json['borrow_id']);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'User ID and Borrow ID are required.']);
        }
        break;
    case 'fetchBorrowedBooks':
        if (isset($json['user_id'])) {
            $book->fetchBorrowedBooks($json['user_id']);
        } else {
            $book->fetchBorrowedBooks(); // Call with no userId
        }
        break;
    case 'updateReservationStatus':
        if (isset($json['reservation_id']) && isset($json['status_id'])) {
            $book->updateReservationStatus($json['reservation_id'], $json['status_id']);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Reservation ID and Status ID are required.']);
        }
        break;

        // ** Notif Functions **
   case 'fetchNotifications':
    if (isset($json['user_id'])) {
        // Open the file in append mode
        $file = fopen('userId.log', 'a');
       

        // Call fetchNotifications method
        $book->fetchNotifications($json['user_id']);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'User ID is required.']);
    }
    break;
    case 'fetchUnreadCount':
        if (isset($json['user_id'])) {
            $book->fetchUnreadCount($json['user_id']);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'User ID is required.']);
        }
        break;
   case 'markNotificationAsRead':
    if ( isset($json['notificationId'])) {
 

        $notificationId = $json['notificationId']; 

        $book->markNotificationAsRead($notificationId);
    } else {
        http_response_code(400); // Bad Request
        echo json_encode(['success' => false, 'message' => 'User ID are required.']);
    }
    break;


    // ** Notification Functions**
    case 'sendOverdueNotices':
        $book->sendOverdueNotices();
        break;
    case 'sendReservationExpiryReminders':
        $book->sendReservationExpiryReminders();
        break;
    case 'sendDueDateReminders':
        $book->sendDueDateReminders();
        break;
    default:
        http_response_code(400); // Bad Request
        echo json_encode(['success' => false, 'message' => 'Invalid operation.']);
        break;
}

?>
