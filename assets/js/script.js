'use strict';

function initializeCampusTrack() {

    // LOGIN AND SETUP PASSWORD TOGGLES
    document.querySelectorAll('[data-toggle-password]')
        .forEach((button) => {
            const input = document.getElementById(
                button.dataset.togglePassword
            );

            if (!input) return;

            button.addEventListener('click', () => {
                const show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                button.textContent = show ? 'Hide' : 'Show';
                button.setAttribute(
                    'aria-label',
                    show ? 'Hide password' : 'Show password'
                );
            });
        });

    // STUDENT REGISTRATION
    const form = document.getElementById('registrationForm');

    if (!form) return;

    const program = document.getElementById('program');
    const college = document.getElementById('college');

    // AUTOMATIC COLLEGE ASSIGNMENT
    if (program && college) {

        function updateCollege() {
            const selectedOption =
                program.options[program.selectedIndex];

            college.value = selectedOption
                ? (selectedOption.getAttribute('data-college') || '')
                : '';
        }

        program.addEventListener('change', updateCollege);
        program.addEventListener('input', updateCollege);

        // Also fill the college on initial page load.
        updateCollege();

        // Handle values restored by the browser.
        window.addEventListener('pageshow', updateCollege);
    }

    // STUDENT NUMBER FORMAT
    const student = document.getElementById('student_number');

    if (student) {
        student.addEventListener('input', () => {
            const digits = student.value
                .replace(/\D/g, '')
                .slice(0, 9);

            student.value = digits.length > 4
                ? digits.slice(0, 4) + '-' + digits.slice(4)
                : digits;
        });
    }

    // PLM EMAIL VALIDATION
    const email = document.getElementById('email');

    function validateEmail() {
        if (!email) return;

        const value = email.value.trim();

        email.setCustomValidity(
            value && !/^[^\s@]+@plm\.edu\.ph$/i.test(value)
                ? 'Use a valid @plm.edu.ph email.'
                : ''
        );
    }

    if (email) {
        email.addEventListener('input', validateEmail);
    }

    // PASSWORD VALIDATION
    const password = document.getElementById('password');
    const confirmation =
        document.getElementById('confirm_password');
    const feedback =
        document.getElementById('password-feedback');

    function validatePasswords() {
        if (!password || !confirmation) return;

        const strong =
            /^(?=.*[A-Z])(?=.*[a-z])(?=.*\d).{10,}$/
                .test(password.value);

        password.setCustomValidity(
            password.value && !strong
                ? 'Use 10+ characters, uppercase, lowercase, and a number.'
                : ''
        );

        const matching =
            password.value === confirmation.value;

        confirmation.setCustomValidity(
            confirmation.value && !matching
                ? 'Passwords do not match.'
                : ''
        );

        if (feedback) {
            feedback.classList.remove(
                'hint-good',
                'hint-error'
            );

            if (!password.value) {
                feedback.textContent = '';
            } else if (!strong) {
                feedback.textContent =
                    'Password must contain 10+ characters, uppercase, lowercase, and a number.';
                feedback.classList.add('hint-error');
            } else if (confirmation.value && !matching) {
                feedback.textContent =
                    'Passwords do not match.';
                feedback.classList.add('hint-error');
            } else {
                feedback.textContent =
                    confirmation.value
                        ? 'Passwords match.'
                        : 'Password format looks good.';
                feedback.classList.add('hint-good');
            }
        }
    }

    if (password && confirmation) {
        password.addEventListener('input', validatePasswords);
        confirmation.addEventListener('input', validatePasswords);
    }

    // SHOW BOTH PASSWORDS
    const showPasswords =
        document.getElementById('show_password');

    if (showPasswords && password && confirmation) {
        showPasswords.addEventListener('change', () => {
            const type = showPasswords.checked
                ? 'text'
                : 'password';

            password.type = type;
            confirmation.type = type;
        });
    }

    // VALIDATE BEFORE SUBMITTING
    form.addEventListener('submit', (event) => {
        validateEmail();
        validatePasswords();

        if (!form.checkValidity()) {
            event.preventDefault();
            form.reportValidity();
        }
    });
}

// RUN AFTER THE DOCUMENT HAS LOADED
if (document.readyState === 'loading') {
    document.addEventListener(
        'DOMContentLoaded',
        initializeCampusTrack
    );
} else {
    initializeCampusTrack();
}
