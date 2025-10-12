// Dashboard JavaScript Functions

// Edit Service
function editService(serviceId) {
    if (confirm('Are you sure you want to edit this service?')) {
        window.location.href = `edit-service.php?id=${serviceId}`;
    }
}

// Archive Service
function archiveService(serviceId) {
    if (confirm('Are you sure you want to archive this service?')) {
        // Send AJAX request to archive service
        fetch('api/archive-service.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ service_id: serviceId })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Service archived successfully!');
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

// Delete Service
function deleteService(serviceId) {
    if (confirm('Are you sure you want to delete this service? This action cannot be undone.')) {
        // Send AJAX request to delete service
        fetch('api/delete-service.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ service_id: serviceId })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Service deleted successfully!');
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

// Load more reviews (if pagination is needed)
function loadMoreReviews() {
    // Implementation for loading more reviews
    console.log('Loading more reviews...');
}

// Filter services by status
function filterServices(status) {
    const cards = document.querySelectorAll('.service-card');
    
    cards.forEach(card => {
        const badge = card.querySelector('.badge');
        if (status === 'all' || badge.textContent === status) {
            card.style.display = 'block';
        } else {
            card.style.display = 'none';
        }
    });
}

// Initialize page
document.addEventListener('DOMContentLoaded', function() {
    console.log('Dashboard loaded');
    
    // Add any initialization code here
    // For example, tooltips, dynamic content loading, etc.
});
