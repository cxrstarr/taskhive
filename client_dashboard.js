// Client Dashboard JavaScript Functions

let selectedRating = 0;

// Initialize page
document.addEventListener('DOMContentLoaded', function() {
    console.log('Client Dashboard loaded');
    
    // Initialize star rating
    const stars = document.querySelectorAll('.star-rating i');
    stars.forEach(star => {
        star.addEventListener('click', function() {
            const rating = parseInt(this.getAttribute('data-rating'));
            setRating(rating);
        });

        star.addEventListener('mouseenter', function() {
            const rating = parseInt(this.getAttribute('data-rating'));
            highlightStars(rating);
        });
    });

    // Reset stars on mouse leave
    const starRating = document.querySelector('.star-rating');
    if (starRating) {
        starRating.addEventListener('mouseleave', function() {
            highlightStars(selectedRating);
        });
    }

    // Character counter for review textarea
    const textarea = document.getElementById('comment');
    const charCount = document.getElementById('charCount');
    if (textarea && charCount) {
        textarea.addEventListener('input', function() {
            const length = this.value.length;
            charCount.textContent = length;
            if (length > 500) {
                this.value = this.value.substring(0, 500);
                charCount.textContent = '500';
            }
        });
    }

    // Close modal when clicking outside
    const modal = document.getElementById('reviewModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                closeReviewModal();
            }
        });
    }

    // Prevent modal content click from closing modal
    const modalContent = document.querySelector('.modal-content');
    if (modalContent) {
        modalContent.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    }
});

// Set rating
function setRating(rating) {
    selectedRating = rating;
    document.getElementById('rating').value = rating;
    highlightStars(rating);
    updateRatingText(rating);
}

// Update rating text description
function updateRatingText(rating) {
    const ratingText = document.getElementById('ratingText');
    const descriptions = {
        1: '⭐ Poor',
        2: '⭐⭐ Fair', 
        3: '⭐⭐⭐ Good',
        4: '⭐⭐⭐⭐ Very Good',
        5: '⭐⭐⭐⭐⭐ Excellent'
    };
    ratingText.textContent = descriptions[rating] || 'Select a rating';
    ratingText.style.color = rating > 0 ? '#F59E0B' : '#6b7280';
}

// Highlight stars
function highlightStars(rating) {
    const stars = document.querySelectorAll('.star-rating i');
    stars.forEach((star, index) => {
        if (index < rating) {
            star.classList.add('active');
        } else {
            star.classList.remove('active');
        }
    });
}

// Open review modal
function openReviewModal(bookingId) {
    const modal = document.getElementById('reviewModal');
    const bookingIdInput = document.getElementById('bookingId');
    
    // Set booking ID
    bookingIdInput.value = bookingId;
    
    // Get booking info from the table row
    const row = event.target.closest('tr');
    if (row) {
        const serviceName = row.querySelector('.service-name')?.textContent || '-';
        const freelancerName = row.querySelector('.freelancer-cell span')?.textContent || '-';
        const freelancerAvatar = row.querySelector('.freelancer-avatar-sm')?.src || '';
        
        // Populate modal with booking info
        document.getElementById('modalServiceName').textContent = serviceName;
        document.getElementById('modalFreelancerName').textContent = freelancerName;
        document.getElementById('modalFreelancerAvatar').src = freelancerAvatar;
        document.getElementById('modalFreelancerAvatar').alt = freelancerName;
    }
    
    // Reset form
    document.getElementById('reviewForm').reset();
    selectedRating = 0;
    highlightStars(0);
    updateRatingText(0);
    document.getElementById('charCount').textContent = '0';
    
    // Show modal
    modal.classList.add('show');
    document.body.style.overflow = 'hidden';
}

// Close review modal
function closeReviewModal() {
    const modal = document.getElementById('reviewModal');
    modal.classList.remove('show');
    document.body.style.overflow = 'auto';
}

// Submit review
function submitReview(event) {
    event.preventDefault();
    
    const bookingId = document.getElementById('bookingId').value;
    const rating = document.getElementById('rating').value;
    const comment = document.getElementById('comment').value;
    
    // Validate rating
    if (rating === '0' || rating === '') {
        alert('Please select a rating');
        return;
    }
    
    // Send AJAX request to submit review
    fetch('api/submit-review.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            booking_id: bookingId,
            rating: rating,
            comment: comment
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Review submitted successfully!');
            closeReviewModal();
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred. Please try again.');
    });
}

// Cancel booking
function cancelBooking(bookingId) {
    if (confirm('Are you sure you want to cancel this booking?')) {
        fetch('api/cancel-booking.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ booking_id: bookingId })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Booking cancelled successfully!');
                location.reload();
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred. Please try again.');
        });
    }
}

// View booking details
function viewBookingDetails(bookingId) {
    window.location.href = `booking-details.php?id=${bookingId}`;
}

// Filter bookings by status
function filterBookings(status) {
    const rows = document.querySelectorAll('.bookings-table tbody tr');
    
    rows.forEach(row => {
        const statusBadge = row.querySelector('.badge');
        const statusText = statusBadge ? statusBadge.textContent.trim() : '';
        
        if (status === 'all') {
            row.style.display = '';
        } else if (statusText.toLowerCase() === status.toLowerCase()) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

// Sort bookings table
function sortBookings(column) {
    console.log('Sorting by:', column);
    // Implementation for sorting functionality
    // This would require storing table data and re-rendering
}

// Load more bookings (pagination)
function loadMoreBookings(page) {
    console.log('Loading page:', page);
    // Implementation for pagination
}
