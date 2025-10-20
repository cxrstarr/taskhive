<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="img/bee.jpg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Choose Your Role - BeeHive</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            min-height: 100vh;
            background-color: #FFFBEB;
            background-image: 
                repeating-linear-gradient(0deg, transparent, transparent 86.6px, rgba(252, 211, 77, 0.15) 86.6px, rgba(252, 211, 77, 0.15) 87.6px),
                repeating-linear-gradient(60deg, transparent, transparent 86.6px, rgba(252, 211, 77, 0.15) 86.6px, rgba(252, 211, 77, 0.15) 87.6px),
                repeating-linear-gradient(120deg, transparent, transparent 86.6px, rgba(252, 211, 77, 0.15) 86.6px, rgba(252, 211, 77, 0.15) 87.6px),
                repeating-linear-gradient(180deg, transparent, transparent 86.6px, rgba(252, 211, 77, 0.15) 86.6px, rgba(252, 211, 77, 0.15) 87.6px),
                repeating-linear-gradient(240deg, transparent, transparent 86.6px, rgba(252, 211, 77, 0.15) 86.6px, rgba(252, 211, 77, 0.15) 87.6px),
                repeating-linear-gradient(300deg, transparent, transparent 86.6px, rgba(252, 211, 77, 0.15) 86.6px, rgba(252, 211, 77, 0.15) 87.6px);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
            overflow: hidden;
        }

        .container {
            width: 100%;
            max-width: 56rem;
            position: relative;
            z-index: 10;
        }

        /* Floating Bees */
        .floating-bee {
            position: fixed;
            font-size: 4rem;
            opacity: 0.2;
            pointer-events: none;
            z-index: 1;
        }

        .bee-1 {
            top: 5rem;
            left: 2.5rem;
            animation: float1 5s ease-in-out infinite;
        }

        .bee-2 {
            bottom: 8rem;
            right: 5rem;
            font-size: 3.5rem;
            animation: float2 6s ease-in-out infinite 1s;
        }

        .bee-3 {
            top: 50%;
            left: 25%;
            font-size: 2.5rem;
            opacity: 0.1;
            animation: float3 7s ease-in-out infinite 2s;
        }

        .bee-4 {
            top: 25%;
            right: 33%;
            font-size: 2rem;
            opacity: 0.15;
            animation: float1 5.5s ease-in-out infinite 0.5s;
        }

        @keyframes float1 {
            0%, 100% { transform: translate(0, 0) rotate(0deg); }
            25% { transform: translate(15px, -25px) rotate(10deg); }
            50% { transform: translate(0, -50px) rotate(0deg); }
            75% { transform: translate(-15px, -25px) rotate(-10deg); }
        }

        @keyframes float2 {
            0%, 100% { transform: translate(0, 0) rotate(0deg); }
            25% { transform: translate(-20px, 25px) rotate(-12deg); }
            50% { transform: translate(0, 50px) rotate(0deg); }
            75% { transform: translate(20px, 25px) rotate(12deg); }
        }

        @keyframes float3 {
            0%, 100% { transform: translate(0, 0); }
            33% { transform: translate(30px, -40px); }
            66% { transform: translate(-30px, -30px); }
        }

        /* Back Button */
        .back-button {
            background: white;
            border: 2px solid #FCD34D;
            color: #92400E;
            padding: 0.625rem 1.25rem;
            border-radius: 0.5rem;
            text-decoration: none;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            transition: all 0.2s ease;
            animation: fadeInDown 0.6s ease-out 0.2s backwards;
        }

        .back-button:hover {
            background: #FEF3C7;
            border-color: #FBBF24;
            transform: translateY(-2px);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        @keyframes fadeInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Main Card */
        .main-card {
            background: white;
            border-radius: 1.5rem;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            border: 4px solid #FCD34D;
            overflow: hidden;
            animation: slideUp 0.6s ease-out;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Header */
        .header {
            background: linear-gradient(135deg, #FEF3C7 0%, #FDE68A 100%);
            padding: 2rem;
            border-bottom: 2px solid #FDE68A;
            animation: fadeIn 0.6s ease-out 0.3s backwards;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .header-content {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .bee-logo {
            width: 3rem;
            height: 3rem;
            background: linear-gradient(135deg, #FBBF24 0%, #F59E0B 100%);
            border-radius: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            animation: bounce 2s ease-in-out infinite;
        }

        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-5px); }
        }

        .header h1 {
            font-size: 1.875rem;
            background: linear-gradient(135deg, #D97706 0%, #92400E 100%);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
            margin: 0;
        }

        /* Content */
        .content {
            padding: 2rem;
        }

        .role-cards {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        /* Role Card */
        .role-card {
            background: linear-gradient(135deg, #F9FAFB 0%, #F3F4F6 100%);
            border-radius: 1rem;
            padding: 1.5rem;
            border: 2px solid #E5E7EB;
            transition: all 0.3s ease;
            cursor: pointer;
            position: relative;
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        .role-card.freelancer {
            animation: slideInLeft 0.5s ease-out 0.4s backwards;
        }

        .role-card.client {
            background: linear-gradient(135deg, #FDF2F8 0%, #FCE7F3 100%);
            border-color: #FBCFE8;
            animation: slideInRight 0.5s ease-out 0.4s backwards;
        }

        @keyframes slideInLeft {
            from {
                opacity: 0;
                transform: translateX(-30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .role-card:hover {
            transform: scale(1.03);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            border-color: #FBBF24;
        }

        .role-card.freelancer:hover .role-icon {
            animation: wiggle 0.5s ease-in-out;
        }

        .role-card.client:hover .role-icon {
            animation: wiggle 0.5s ease-in-out;
        }

        @keyframes wiggle {
            0%, 100% { transform: rotate(0deg); }
            25% { transform: rotate(-5deg); }
            50% { transform: rotate(5deg); }
            75% { transform: rotate(-5deg); }
        }

        .sparkle {
            position: absolute;
            top: -0.5rem;
            right: -0.5rem;
            font-size: 2rem;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .role-card:hover .sparkle {
            opacity: 1;
            animation: sparkleAnim 0.6s ease-out;
        }

        @keyframes sparkleAnim {
            0% { transform: scale(0.5) rotate(0deg); }
            50% { transform: scale(1.2) rotate(180deg); }
            100% { transform: scale(1) rotate(360deg); }
        }

        .role-icon {
            width: 4rem;
            height: 4rem;
            background: linear-gradient(135deg, #A78BFA 0%, #7C3AED 100%);
            border-radius: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .role-card.client .role-icon {
            background: linear-gradient(135deg, #F9A8D4 0%, #EC4899 100%);
        }

        .role-icon i {
            font-size: 2rem;
            color: white;
        }

        .role-card h3 {
            text-align: center;
            color: #1F2937;
            margin: 0 0 0.5rem 0;
        }

        .role-description {
            text-align: center;
            font-size: 0.875rem;
            color: #6B7280;
            margin-bottom: 1.5rem;
        }

        .features {
            list-style: none;
            padding: 0;
            margin: 0 0 1.5rem 0;
            flex-grow: 1;
        }

        .features li {
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
            font-size: 0.875rem;
            color: #374151;
            animation: fadeInUp 0.3s ease-out backwards;
        }

        .features li:nth-child(1) { animation-delay: 0.5s; }
        .features li:nth-child(2) { animation-delay: 0.6s; }
        .features li:nth-child(3) { animation-delay: 0.7s; }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .features li i {
            color: #10B981;
            margin-top: 0.125rem;
            flex-shrink: 0;
        }

        .role-button {
            width: 100%;
            padding: 0.75rem 1.5rem;
            background: linear-gradient(135deg, #FBBF24 0%, #F59E0B 100%);
            border: none;
            border-radius: 0.75rem;
            color: white;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 6px -1px rgba(245, 158, 11, 0.4);
        }

        .role-button:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 15px -3px rgba(245, 158, 11, 0.5);
        }

        .role-button:active {
            transform: translateY(-1px);
        }

        /* Login Link */
        .login-link {
            text-align: center;
            color: #6B7280;
            font-size: 0.9375rem;
            animation: fadeIn 0.6s ease-out 0.8s backwards;
        }

        .login-link a {
            color: #D97706;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.2s ease;
        }

        .login-link a:hover {
            color: #92400E;
            text-decoration: underline;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .role-cards {
                grid-template-columns: 1fr;
            }

            .header h1 {
                font-size: 1.5rem;
            }

            .floating-bee {
                font-size: 2.5rem;
            }

            .role-card.freelancer,
            .role-card.client {
                animation: fadeIn 0.6s ease-out 0.4s backwards;
            }
        }
    </style>
</head>
<body>
    <!-- Floating Bees -->
    <div class="floating-bee bee-1">🐝</div>
    <div class="floating-bee bee-2">🐝</div>
    <div class="floating-bee bee-3">🐝</div>
    <div class="floating-bee bee-4">🐝</div>

    <div class="container">
        <!-- Back Button -->
    <a href="index.php" class="back-button">
            <i class="fas fa-arrow-left"></i>
            Back
        </a>

        <!-- Main Card -->
        <div class="main-card">
            <!-- Header -->
            <div class="header">
                <div class="header-content">
                    <div class="bee-logo">🐝</div>
                    <h1>Choose Your Role</h1>
                </div>
            </div>

            <!-- Content -->
            <div class="content">
                <div class="role-cards">
                    <!-- Freelancer Card -->
                    <div class="role-card freelancer" onclick="selectRole('freelancer')">
                        <span class="sparkle">✨</span>
                        <div class="role-icon">
                            <i class="fas fa-briefcase"></i>
                        </div>
                        <h3>Freelancer</h3>
                        <p class="role-description">Offer services & earn</p>
                        <ul class="features">
                            <li>
                                <i class="fas fa-check"></i>
                                <span>Create service listings</span>
                            </li>
                            <li>
                                <i class="fas fa-check"></i>
                                <span>Manage bookings</span>
                            </li>
                            <li>
                                <i class="fas fa-check"></i>
                                <span>Receive reviews</span>
                            </li>
                        </ul>
                        <button class="role-button" onclick="selectRole('freelancer'); event.stopPropagation();">
                            Register as Freelancer
                        </button>
                    </div>

                    <!-- Client Card -->
                    <div class="role-card client" onclick="selectRole('client')">
                        <span class="sparkle">✨</span>
                        <div class="role-icon">
                            <i class="fas fa-star"></i>
                        </div>
                        <h3>Client</h3>
                        <p class="role-description">Hire trusted local talent</p>
                        <ul class="features">
                            <li>
                                <i class="fas fa-check"></i>
                                <span>Browse services</span>
                            </li>
                            <li>
                                <i class="fas fa-check"></i>
                                <span>Book easily</span>
                            </li>
                            <li>
                                <i class="fas fa-check"></i>
                                <span>Review freelancers</span>
                            </li>
                        </ul>
                        <button class="role-button" onclick="selectRole('client'); event.stopPropagation();">
                            Register as Client
                        </button>
                    </div>
                </div>

                <!-- Login Link -->
                <div class="login-link">
                    Already have an account? <a href="login.php">Login</a>
                </div>
            </div>
        </div>
    </div>

    <script>
        function selectRole(role) {
            // Add a nice animation before redirecting
            const card = document.querySelector(`.role-card.${role}`);
            card.style.transform = 'scale(0.95)';
            
            setTimeout(() => {
                if (role === 'freelancer') {
                    window.location.href = 'freelancer.php';
                } else {
                    window.location.href = 'client.php';
                }
            }, 200);
        }

        // Add hover sound effect (optional - uncomment if you want sound)
        /*
        const cards = document.querySelectorAll('.role-card');
        cards.forEach(card => {
            card.addEventListener('mouseenter', () => {
                // You can add a subtle hover sound here
                console.log('Card hovered');
            });
        });
        */
    </script>
</body>
</html>
