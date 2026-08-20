<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TraceGeo — Login</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        :root {
            --bg: #0a0a0f;
            --bg-card: #12121a;
            --bg-input: #1a1a28;
            --border: rgba(255,255,255,0.06);
            --text: #ffffff;
            --text-secondary: #9ca3af;
            --text-muted: #6b7280;
            --accent: #8b5cf6;
            --success: #34d399;
            --radius: 12px;
            --radius-lg: 20px;
            --shadow: 0 8px 32px rgba(0,0,0,0.4);
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: radial-gradient(ellipse at center, #0a0a1a 0%, #05050a 100%);
        }

        .bg-glow {
            position: fixed;
            inset: 0;
            z-index: 0;
            pointer-events: none;
            background: radial-gradient(ellipse at 50% 50%, rgba(139,92,246,0.08), transparent 70%);
            animation: glowPulse 8s ease-in-out infinite alternate;
        }

        @keyframes glowPulse {
            0% { opacity: 0.6; }
            100% { opacity: 1; }
        }

        .auth-container {
            position: relative;
            z-index: 5;
            width: 100%;
            max-width: 420px;
            padding: 20px;
        }

        .auth-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 40px 36px;
            box-shadow: var(--shadow);
            backdrop-filter: blur(20px);
        }

        .auth-logo {
            text-align: center;
            margin-bottom: 32px;
        }

        .auth-logo .icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--accent), #6d28d9);
            color: #fff;
            font-size: 24px;
            font-weight: 700;
            font-family: 'Space Grotesk', sans-serif;
            margin-bottom: 12px;
        }

        .auth-logo h1 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 28px;
            font-weight: 700;
            background: linear-gradient(135deg, #fff 30%, var(--success) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .auth-logo p {
            color: var(--text-secondary);
            font-size: 14px;
            margin-top: 4px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-secondary);
            margin-bottom: 6px;
        }

        .form-group input {
            width: 100%;
            padding: 12px 16px;
            border-radius: var(--radius);
            border: 1px solid var(--border);
            background: var(--bg-input);
            color: var(--text);
            font-size: 14px;
            font-family: 'Inter', sans-serif;
            transition: border-color 0.3s ease;
            outline: none;
        }

        .form-group input:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(139,92,246,0.1);
        }

        .form-group input::placeholder {
            color: var(--text-muted);
        }

        .form-options {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }

        .form-options label {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-secondary);
            font-size: 13px;
            cursor: pointer;
        }

        .form-options label input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: var(--accent);
        }

        .form-options a {
            color: var(--accent);
            font-size: 13px;
            text-decoration: none;
        }

        .form-options a:hover {
            text-decoration: underline;
        }

        .btn {
            width: 100%;
            padding: 12px;
            border-radius: var(--radius);
            border: none;
            font-size: 15px;
            font-weight: 600;
            transition: all 0.3s ease;
            cursor: pointer;
            font-family: 'Inter', sans-serif;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--accent), #6d28d9);
            color: #fff;
            box-shadow: 0 4px 16px rgba(139,92,246,0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(139,92,246,0.5);
        }

        .btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .auth-footer {
            text-align: center;
            margin-top: 24px;
            color: var(--text-secondary);
            font-size: 14px;
        }

        .auth-footer a {
            color: var(--accent);
            text-decoration: none;
            font-weight: 600;
        }

        .auth-footer a:hover {
            text-decoration: underline;
        }

        .error-message {
            background: rgba(248,113,113,0.12);
            border: 1px solid rgba(248,113,113,0.2);
            color: #f87171;
            padding: 10px 14px;
            border-radius: var(--radius);
            font-size: 13px;
            margin-bottom: 16px;
            display: none;
        }

        .error-message.show {
            display: block;
        }

        .success-message {
            background: rgba(52,211,153,0.12);
            border: 1px solid rgba(52,211,153,0.2);
            color: var(--success);
            padding: 10px 14px;
            border-radius: var(--radius);
            font-size: 13px;
            margin-bottom: 16px;
            display: none;
        }

        .success-message.show {
            display: block;
        }

        @media (max-width: 480px) {
            .auth-card {
                padding: 28px 20px;
            }
            .auth-logo h1 {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>

<div class="bg-glow"></div>

<div class="auth-container">
    <div class="auth-card">
        <div class="auth-logo">
            <div class="icon">T</div>
            <h1>TraceGeo</h1>
            <p>Sign in to your account</p>
        </div>

        <!-- ✅ Display validation errors from database authentication -->
        @if($errors->any())
            <div class="error-message show">
                {{ $errors->first() }}
            </div>
        @endif

        @if(session('success'))
            <div class="success-message show">
                {{ session('success') }}
            </div>
        @endif

        <!-- ✅ FORM SUBMITS TO /login - AUTHENTICATES AGAINST DATABASE -->
        <form method="POST" action="/login" id="loginForm">
            @csrf
            <div class="form-group">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}" placeholder="you@example.com" required autofocus>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" placeholder="Enter your password" required>
            </div>

            <div class="form-options">
                <label>
                    <input type="checkbox" name="remember_me" value="1"> Remember me
                </label>
                <a href="#">Forgot password?</a>
            </div>

            <button type="submit" class="btn btn-primary" id="loginBtn">
                <span id="loginText">Sign In</span>
                <span id="loginSpinner" style="display:none;">
                    <i class="fas fa-spinner fa-spin"></i> Signing in...
                </span>
            </button>
        </form>

        <div class="auth-footer">
            Don't have an account? <a href="/register">Create one</a>
        </div>
    </div>
</div>

<script>
// ============================================
// LOGIN FORM HANDLER - REAL DATABASE AUTH
// ============================================

document.getElementById('loginForm').addEventListener('submit', function(e) {
    const btn = document.getElementById('loginBtn');
    const text = document.getElementById('loginText');
    const spinner = document.getElementById('loginSpinner');
    
    // Show loading state
    btn.disabled = true;
    text.style.display = 'none';
    spinner.style.display = 'inline';
    
    // The form will submit normally to /login
    // The AuthController will check the database
    // No AJAX needed - standard form submission
    
    // If there's an error, the page will reload with errors
    // If successful, user will be redirected to /
});

// ✅ Debug - Check if CSRF token exists
console.log('CSRF Token:', document.querySelector('meta[name="csrf-token"]')?.content);
console.log('Form _token:', document.querySelector('input[name="_token"]')?.value);
console.log('✅ Login form uses REAL database authentication');
</script>

</body>
</html>