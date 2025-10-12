<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>TaskHive 🐝 - A Community Buzzing with Services</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <!-- Custom CSS for Bee Theme -->
  <link rel="stylesheet" href="style.css">
</head>
<body>

<!-- Navigation Bar -->
<nav class="navbar navbar-expand-lg navbar-dark">
  <div class="container">
    <a class="navbar-brand" href="#">TaskHive 🐝</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" 
            aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navbarNav">
      <ul class="navbar-nav ms-auto">
        <li class="nav-item"><a class="nav-link" href="#services">Services</a></li>
        <li class="nav-item"><a class="nav-link" href="#how-it-works">How It Works</a></li>
        <li class="nav-item"><a class="nav-link" href="#monetization">Monetization</a></li>
        <li class="nav-item"><a class="nav-link" href="#contact-us">Contact Us</a></li>

        <!-- Custom Dropdown -->
        <li class="nav-item">
          <div class="dropdown">
            <button class="btn btn-hive" id="dropdownToggle" aria-expanded="false">
              Join the Hive
            </button>
            <div class="dropdown-menu" id="dropdownMenu" role="menu" aria-hidden="true">
              <a role="menuitem" href="login.php">Login</a>
              <a role="menuitem" href="register.php">Register</a>
            </div>
          </div>
        </li>
      </ul>
    </div>
  </div>
</nav>

<!-- Hero Section -->
<section class="hero">
    
  <div class="container">
    <h1>Welcome to TaskHive 🐝</h1>
    <p>A Community Buzzing with Services</p>
    <p>From home tasks to personal projects, find trusted help or offer your skills locally with ease.</p>
  </div>
</section>



 <section id="services" class="services py-5">
  <div class="container">
    <h2 class="text-center mb-5">Popular Services</h2>
    <div class="row g-4">
      
      <!-- Delivery -->
      <div class="col-md-4">
        <div class="card service-card h-100 shadow-sm border-0 text-center">
            <img src="images/delivery.png" class="card-img-top service-icon mx-auto mt-3" alt="Delivery">
          <div class="card-body">
            <h5 class="card-title">Delivery</h5>
            <p class="card-text">Swift and reliable local delivery services.</p>
          </div>
        </div>
      </div>

      <!-- Babysitting -->
      <div class="col-md-4">
        <div class="card service-card h-100 shadow-sm border-0 text-center">
          <img src="images/babysitting.png" class="card-img-top service-icon mx-auto mt-3" alt="Babysitting">
          <div class="card-body">
            <h5 class="card-title">Babysitting</h5>
            <p class="card-text">Trusted caregivers for your little ones.</p>
          </div>
        </div>
      </div>

      <!-- Handyman -->
      <div class="col-md-4">
        <div class="card service-card h-100 shadow-sm border-0 text-center">
          <img src="images/handyman.png" class="card-img-top service-icon mx-auto mt-3" alt="Handyman Work">
          <div class="card-body">
            <h5 class="card-title">Handyman Work</h5>
            <p class="card-text">Fixes and repairs to keep your hive humming.</p>
          </div>
        </div>
      </div>

      <!-- Tutoring -->
      <div class="col-md-4">
        <div class="card service-card h-100 shadow-sm border-0 text-center">
          <img src="images/tutoring.png" class="card-img-top service-icon mx-auto mt-3" alt="Tutoring">
          <div class="card-body">
            <h5 class="card-title">Tutoring</h5>
            <p class="card-text">Personalized learning to help you soar.</p>
          </div>
        </div>
      </div>

      <!-- Pet Sitting -->
      <div class="col-md-4">
        <div class="card service-card h-100 shadow-sm border-0 text-center">
          <img src="images/petsitting.png" class="card-img-top service-icon mx-auto mt-3" alt="Pet Sitting">
          <div class="card-body">
            <h5 class="card-title">Pet Sitting</h5>
            <p class="card-text">Loving care for your pets while you're away.</p>
          </div>
        </div>
      </div>

      <!-- House Cleaning -->
      <div class="col-md-4">
        <div class="card service-card h-100 shadow-sm border-0 text-center">
          <img src="images/cleaning.png" class="card-img-top service-icon mx-auto mt-3" alt="House Cleaning">
          <div class="card-body">
            <h5 class="card-title">House Cleaning</h5>
            <p class="card-text">Professional cleaning services to keep your home sparkling.</p>
          </div>
        </div>
      </div>

    </div>
  </div>
</section>

<!-- Freelancer Showcase Section -->
<section id="freelancers" class="freelancers py-5">
  <div class="container">
    <h2 class="text-center mb-5">Freelancer who offers services</h2>
    <div class="row g-4">

      <!-- Freelancer 1 -->
      <div class="col-md-4">
        <div class="card h-100 shadow-sm border-0 text-center p-3">
          <img src="img/freelance1.webp" 
               class="card-img-top rounded-circle mx-auto mt-3" 
               alt="Freelancer 1" 
               style="width:120px; height:120px; object-fit:cover;">
          <div class="card-body d-flex flex-column">
            <h5 class="card-title">Maria Santos</h5>
            <p class="card-text text-muted">Web Developer</p>
            <p class="card-text flex-grow-1">
              Passionate about building modern, responsive websites. Skilled in HTML, CSS, JavaScript, and PHP.
            </p>
            <a href="login.php" class="btn btn-hive mt-auto">Hire</a>
          </div>
        </div>
      </div>

      

      <!-- Freelancer 2 -->
      <div class="col-md-4">
        <div class="card h-100 shadow-sm border-0 text-center p-3">
          <img src="img/freelance2.webp" 
               class="card-img-top rounded-circle mx-auto mt-3" 
               alt="Freelancer 2" 
               style="width:120px; height:120px; object-fit:cover;">
          <div class="card-body d-flex flex-column">
            <h5 class="card-title">John Reyes</h5>
            <p class="card-text text-muted">Graphic Designer</p>
            <p class="card-text flex-grow-1">
              Creative designer specializing in logos, posters, and brand identity. Turning ideas into visuals.
            </p>
            <a href="login.php" class="btn btn-hive mt-auto">Hire</a>
          </div>
        </div>
      </div>

      <!-- Freelancer 3 -->
      <div class="col-md-4">
        <div class="card h-100 shadow-sm border-0 text-center p-3">
          <img src="img/freelance3.jpg" 
               class="card-img-top rounded-circle mx-auto mt-3" 
               alt="Freelancer 3" 
               style="width:120px; height:120px; object-fit:cover;">
          <div class="card-body d-flex flex-column">
            <h5 class="card-title">Angela Cruz</h5>
            <p class="card-text text-muted">Content Writer</p>
            <p class="card-text flex-grow-1">
              Experienced writer delivering engaging blogs, articles, and copywriting tailored to your audience.
            </p>
            <a href="login.php" class="btn btn-hive mt-auto">Hire</a>
          </div>
        </div>
      </div>

    </div>
  </div>
</section>


<section id="reviews" class="reviews py-5">
  <div class="container">
    <h2 class="text-center mb-5">What Our Users Say</h2>
    <div class="row">
      <!-- Review 1 -->
      <div class="col-md-4">
        <div class="card shadow-sm border-0 h-100">
          <div class="card-body text-center">
            <p class="mb-3">“TaskHive made it so easy for me to find reliable help for my home repairs. I love the secure payment system!”</p>
            <h6 class="fw-bold mb-0">– Maria S.</h6>
            <small class="text-muted">Client</small>
          </div>
        </div>
      </div>
      <!-- Review 2 -->
      <div class="col-md-4">
        <div class="card shadow-sm border-0 h-100">
          <div class="card-body text-center">
            <p class="mb-3">“As a freelancer, I’ve been able to grow my side hustle into a full-time job thanks to TaskHive’s premium listing feature.”</p>
            <h6 class="fw-bold mb-0">– John D.</h6>
            <small class="text-muted">Freelancer</small>
          </div>
        </div>
      </div>
      <!-- Review 3 -->
      <div class="col-md-4">
        <div class="card shadow-sm border-0 h-100">
          <div class="card-body text-center">
            <p class="mb-3">“The platform is easy to use, and the customer support team is always responsive. Highly recommend TaskHive!”</p>
            <h6 class="fw-bold mb-0">– Alex R.</h6>
            <small class="text-muted">Client</small>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>



    <!-- How It Works Section -->
<section id="how-it-works" class="how-it-works py-5">
  <div class="container">
    <h2 class="text-center mb-5">How TaskHive Works</h2>
    <div class="row">
      <!-- Step 1 -->
      <div class="col-md-4 text-center mb-4">
        <h4>1. Join the Hive</h4>
        <p>
          Sign up for free as a <strong>client</strong> or <strong>freelancer</strong>.  
          Create your profile, highlight your skills, or list the services you’re looking for.  
          The more detailed your profile, the easier it is to connect with others.
        </p>
      </div>
      <!-- Step 2 -->
      <div class="col-md-4 text-center mb-4">
        <h4>2. List or Browse Services</h4>
        <p>
          <strong>Freelancers:</strong> Post your services with descriptions, rates, and availability.  
          <strong>Clients:</strong> Search through categories, compare offers, and choose the service that best fits your needs.  
          TaskHive makes it simple to connect the right people together.
        </p>
      </div>
      <!-- Step 3 -->
      <div class="col-md-4 text-center mb-4">
        <h4>3. Complete Tasks & Get Paid</h4>
        <p>
          Once a service is agreed upon, the freelancer completes the task.  
          Payments are handled securely through TaskHive, so both clients and freelancers can work with confidence.  
          Leave ratings and reviews to help the community grow stronger.
        </p>
      </div>
    </div>
  </div>
</section>


    <!-- Monetization Section -->
    <section id="monetization" class="monetization py-5">
  <div class="container">
    <h2 class="text-center mb-5">Monetization Options</h2>
    <div class="row">
      <!-- Commission -->
      <div class="col-md-6 mb-4">
        <h4>1. Commission on Tasks</h4>
        <p>
          TaskHive sustains itself by charging a small percentage fee on every completed task.  
          This ensures the platform remains secure and reliable while allowing freelancers to focus on delivering great results.
        </p>
      </div>
      <!-- Premium Listings -->
      <div class="col-md-6 mb-4">
        <h4>2. Premium Listings</h4>
        <p>
          Freelancers can upgrade their profiles or services to premium listings, giving them priority placement in search results.  
          This boosts visibility and increases their chances of being hired by clients.
        </p>
      </div>
      <!-- Subscription Plans -->
      <div class="col-md-6 mb-4">
        <h4>3. Subscription Plans</h4>
        <p>
          Regular freelancers and agencies can subscribe to monthly or yearly plans.  
          These plans unlock benefits like reduced commission fees, exclusive promotional features, and advanced analytics to track performance.
        </p>
      </div>
      <!-- Advertising -->
      <div class="col-md-6 mb-4">
        <h4>4. Advertising Opportunities</h4>
        <p>
          Businesses and service providers can advertise within TaskHive to reach a targeted audience of clients and freelancers.  
          This provides an additional revenue stream while keeping ads relevant and non-intrusive.
        </p>
      </div>
    </div>
  </div>
</section>


    <!-- Contact Us Section -->
<section class="contact-us">
  <div class="contact-card">
    <h2>Contact Us</h2>
    <p>We’d love to hear from you! Fill out the form below.</p>
    <form>
      <input type="text" class="form-control mb-3" placeholder="Your Name">
      <input type="email" class="form-control mb-3" placeholder="Your Email">
      <textarea class="form-control mb-3" rows="4" placeholder="Your Message"></textarea>
      <button type="submit" class="btn btn-hive">Send Message</button>
    </form>
  </div>
</section>


    <!-- Call to Action -->
    <section class="cta">
        <div class="container">
            <h2>Join TaskHive Today! 🐝</h2>
            <p>Be part of a community buzzing with local services.</p>
            <a href="#" class="btn btn-hive btn-lg">Join the Hive</a>
        </div>
    </section>

   <!-- Footer -->
<footer class="footer">
  <div class="container">
    <p>&copy; 2025 TaskHive. All rights reserved.</p>
  </div>
</footer>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- Dropdown Script -->
<script>
const toggleBtn = document.getElementById('dropdownToggle');
const menu = document.getElementById('dropdownMenu');

toggleBtn.addEventListener('click', () => {
  const isExpanded = toggleBtn.getAttribute('aria-expanded') === 'true';
  toggleBtn.setAttribute('aria-expanded', !isExpanded);
  menu.classList.toggle('show');
});

document.addEventListener('click', (e) => {
  if (!toggleBtn.contains(e.target) && !menu.contains(e.target)) {
    toggleBtn.setAttribute('aria-expanded', 'false');
    menu.classList.remove('show');
  }
});
</script>

</body>
</html>