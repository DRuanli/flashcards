</div>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script>
        // includes/footer.php - Common footer for all pages
        document.addEventListener('DOMContentLoaded', function() {
            // Show page spinner on load
            const spinner = document.getElementById('page-spinner');
            spinner.classList.add('show');
            
            // Hide spinner when page is fully loaded
            window.addEventListener('load', function() {
                setTimeout(function() {
                    spinner.classList.remove('show');
                }, 300);
            });
            
            // Flip flashcards when clicked
            const flashcards = document.querySelectorAll('.flashcard');
            flashcards.forEach(card => {
                card.addEventListener('click', function() {
                    this.classList.toggle('flipped');
                });
            });
            
            // Add active class to current nav item
            const currentLocation = window.location.href;
            const navLinks = document.querySelectorAll('.nav-link');
            navLinks.forEach(link => {
                if (currentLocation.includes(link.href) && link.href !== '<?php echo SITE_URL; ?>/') {
                    link.classList.add('active');
                } else if (link.href === '<?php echo SITE_URL; ?>/' && currentLocation === link.href) {
                    link.classList.add('active');
                }
            });
        });
    </script>
</body>
</html>