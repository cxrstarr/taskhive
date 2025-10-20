<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Under Construction - Task Hive</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @keyframes float {
            0%, 100% { 
                transform: translateY(0px) rotate(0deg); 
            }
            50% { 
                transform: translateY(-20px) rotate(5deg); 
            }
        }

        @keyframes flyBee1 {
            0%, 100% { 
                transform: translate(0, 0) rotate(0deg); 
            }
            50% { 
                transform: translate(20vw, -15vh) rotate(10deg); 
            }
        }

        @keyframes flyBee2 {
            0%, 100% { 
                transform: translate(0, 0) rotate(0deg); 
            }
            50% { 
                transform: translate(20vw, -15vh) rotate(-10deg); 
            }
        }

        @keyframes flyBee3 {
            0%, 100% { 
                transform: translate(0, 0) rotate(0deg); 
            }
            50% { 
                transform: translate(20vw, -15vh) rotate(10deg); 
            }
        }

        @keyframes pulse {
            0%, 100% { 
                transform: scale(1); 
            }
            50% { 
                transform: scale(1.05); 
            }
        }

        @keyframes orbitBee1 {
            from { 
                transform: rotate(0deg) translateX(80px) rotate(0deg); 
            }
            to { 
                transform: rotate(360deg) translateX(80px) rotate(-360deg); 
            }
        }

        @keyframes orbitBee2 {
            from { 
                transform: rotate(0deg) translateX(80px) rotate(0deg); 
            }
            to { 
                transform: rotate(360deg) translateX(80px) rotate(-360deg); 
            }
        }

        @keyframes progressBar {
            0% { 
                width: 0%; 
            }
            50% { 
                width: 70%; 
            }
            100% { 
                width: 0%; 
            }
        }

        @keyframes hexagonPulse {
            0%, 100% { 
                opacity: 0.3; 
                transform: scale(0.8); 
            }
            50% { 
                opacity: 1; 
                transform: scale(1); 
            }
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .honeycomb-pattern {
            background-image: radial-gradient(circle, #f59e0b 1px, transparent 1px);
            background-size: 20px 20px;
            animation: float 6s ease-in-out infinite;
        }

        .hexagon {
            clip-path: polygon(50% 0%, 100% 25%, 100% 75%, 50% 100%, 0% 75%, 0% 25%);
        }

        .flying-bee {
            position: absolute;
            font-size: 2.5rem;
            pointer-events: none;
        }

        .flying-bee:nth-child(1) {
            left: 20vw;
            top: 30vh;
            animation: flyBee1 8s ease-in-out infinite;
        }

        .flying-bee:nth-child(2) {
            left: 60vw;
            top: 50vh;
            animation: flyBee2 10s ease-in-out infinite 0.5s;
        }

        .flying-bee:nth-child(3) {
            left: 80vw;
            top: 20vh;
            animation: flyBee3 12s ease-in-out infinite 1s;
        }

        .beehive-icon {
            animation: pulse 2s ease-in-out infinite;
        }

        .orbit-bee-1 {
            position: absolute;
            font-size: 1.5rem;
            animation: orbitBee1 10s linear infinite;
        }

        .orbit-bee-2 {
            position: absolute;
            font-size: 1.5rem;
            animation: orbitBee2 8s linear infinite;
        }

        .progress-fill {
            animation: progressBar 3s ease-in-out infinite;
        }

        .hexagon-item {
            animation: hexagonPulse 2s ease-in-out infinite;
        }

        .hexagon-item:nth-child(1) { animation-delay: 0s; }
        .hexagon-item:nth-child(2) { animation-delay: 0.2s; }
        .hexagon-item:nth-child(3) { animation-delay: 0.4s; }
        .hexagon-item:nth-child(4) { animation-delay: 0.6s; }
        .hexagon-item:nth-child(5) { animation-delay: 0.8s; }
        .hexagon-item:nth-child(6) { animation-delay: 1s; }

        .fade-in-up {
            animation: fadeInUp 0.8s ease-out forwards;
            opacity: 0;
        }

        .delay-200 { animation-delay: 0.2s; }
        .delay-400 { animation-delay: 0.4s; }
        .delay-600 { animation-delay: 0.6s; }
        .delay-800 { animation-delay: 0.8s; }
        .delay-1000 { animation-delay: 1s; }
        .delay-1200 { animation-delay: 1.2s; }

        .back-button {
            transition: all 0.3s ease;
        }

        .back-button:hover {
            transform: scale(1.05);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }

        .back-button:hover .arrow-icon {
            transform: translateX(-4px);
        }

        .arrow-icon {
            transition: transform 0.3s ease;
        }

        .gradient-text {
            background: linear-gradient(to right, #d97706, #f97316, #d97706);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
    </style>
</head>
<body class="bg-gradient-to-br from-amber-50 via-orange-50 to-yellow-50 min-h-screen relative overflow-hidden">
    
    <!-- Animated Honeycomb Background -->
    <div class="absolute inset-0 opacity-10 pointer-events-none">
        <div class="absolute top-10 left-10 w-32 h-32 honeycomb-pattern"></div>
        <div class="absolute top-40 right-20 w-40 h-40 honeycomb-pattern"></div>
        <div class="absolute bottom-20 left-1/4 w-36 h-36 honeycomb-pattern"></div>
        <div class="absolute bottom-40 right-1/3 w-28 h-28 honeycomb-pattern"></div>
    </div>

    <!-- Animated Flying Bees -->
    <div class="flying-bee">🐝</div>
    <div class="flying-bee">🐝</div>
    <div class="flying-bee">🐝</div>

    <!-- Main Content -->
    <div class="relative z-10 flex flex-col items-center justify-center min-h-screen px-4">
        <div class="text-center max-w-2xl">
            
            <!-- Animated Beehive Icon -->
            <div class="mb-8 flex justify-center fade-in-up">
                <div class="relative beehive-icon">
                    <div class="w-32 h-32 bg-gradient-to-br from-amber-400 to-orange-500 rounded-full flex items-center justify-center shadow-2xl">
                        <!-- Hammer Icon (SVG) -->
                        <svg class="w-16 h-16 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.121 14.121L19 19m-7-7l7-7m-7 7l-2.879 2.879M12 12L9.121 9.121m0 5.758a3 3 0 10-4.243 4.243 3 3 0 004.243-4.243zm0-5.758a3 3 0 10-4.243-4.243 3 3 0 004.243 4.243z"></path>
                        </svg>
                    </div>
                    <!-- Orbiting Bees -->
                    <div class="absolute top-0 left-0 w-full h-full flex items-center justify-center">
                        <span class="orbit-bee-1">🐝</span>
                    </div>
                    <div class="absolute top-0 left-0 w-full h-full flex items-center justify-center">
                        <span class="orbit-bee-2">🐝</span>
                    </div>
                </div>
            </div>

            <!-- Main Heading -->
            <h1 class="text-5xl md:text-6xl font-bold mb-6 gradient-text fade-in-up delay-200">
                Hive Under Construction
            </h1>

            <!-- Clever Bee Pun -->
            <p class="text-xl md:text-2xl text-amber-900 mb-4 fade-in-up delay-400">
                Our worker bees are <span class="italic font-semibold">buzzing</span> away on this feature!
            </p>

            <p class="text-lg text-amber-700 mb-8 fade-in-up delay-600">
                <span class="font-bold">BEE</span> patient while we craft something sweet for you.
            </p>

            <!-- Animated Progress Bar -->
            <div class="mb-12 fade-in-up delay-800">
                <div class="bg-white/50 backdrop-blur-sm rounded-full h-4 w-full max-w-md mx-auto overflow-hidden shadow-lg border border-amber-200">
                    <div class="progress-fill h-full bg-gradient-to-r from-amber-400 via-orange-500 to-amber-400 rounded-full"></div>
                </div>
                <p class="text-sm text-amber-600 mt-3">Building the perfect comb...</p>
            </div>

            <!-- Honeycomb Grid Animation -->
            <div class="grid grid-cols-3 gap-3 max-w-xs mx-auto mb-12 fade-in-up delay-800">
                <div class="hexagon-item hexagon w-16 h-16 bg-gradient-to-br from-amber-200 to-orange-300 flex items-center justify-center">
                    <span class="text-xl">🍯</span>
                </div>
                <div class="hexagon-item hexagon w-16 h-16 bg-gradient-to-br from-amber-200 to-orange-300 flex items-center justify-center">
                    <span class="text-xl">🍯</span>
                </div>
                <div class="hexagon-item hexagon w-16 h-16 bg-gradient-to-br from-amber-200 to-orange-300 flex items-center justify-center">
                    <span class="text-xl">🍯</span>
                </div>
                <div class="hexagon-item hexagon w-16 h-16 bg-gradient-to-br from-amber-200 to-orange-300 flex items-center justify-center">
                    <span class="text-xl">🍯</span>
                </div>
                <div class="hexagon-item hexagon w-16 h-16 bg-gradient-to-br from-amber-200 to-orange-300 flex items-center justify-center">
                    <span class="text-xl">🍯</span>
                </div>
                <div class="hexagon-item hexagon w-16 h-16 bg-gradient-to-br from-amber-200 to-orange-300 flex items-center justify-center">
                    <span class="text-xl">🍯</span>
                </div>
            </div>

            <!-- Back Button -->
            <div class="fade-in-up delay-1000">
                <button onclick="window.history.back()" class="back-button group inline-flex items-center gap-2 px-8 py-4 bg-gradient-to-r from-amber-500 to-orange-500 text-white rounded-full shadow-lg">
                    <!-- Arrow Left Icon -->
                    <svg class="arrow-icon w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                    </svg>
                    <span class="font-semibold">Fly Back to Hive</span>
                </button>
            </div>

            <!-- Additional Info -->
            <p class="mt-8 text-sm text-amber-600 fade-in-up delay-1200">
                Don't worry, we never <span class="italic">drone</span> on forever! ✨
            </p>

        </div>
    </div>

</body>
</html>
