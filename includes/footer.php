    </div>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script>
        // includes/footer.php - Common footer for all pages
        // Flip flashcards when clicked
        document.addEventListener('DOMContentLoaded', function() {
            const flashcards = document.querySelectorAll('.flashcard');
            flashcards.forEach(card => {
                card.addEventListener('click', function() {
                    this.classList.toggle('flipped');
                });
            });
        });
    </script>
</body>
</html>
