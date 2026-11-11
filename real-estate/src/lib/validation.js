/**
 * Modern Client-Side Form Validation & XSS Sanitization Library
 */

/**
 * Sanitize text to prevent XSS injection
 */
export function sanitize(text) {
  if (typeof text !== "string") return text;
  return text
    .trim()
    .replace(/[<>]/g, "") // strip angle brackets
    .slice(0, 2000);
}

/**
 * Validate email address format
 */
export function isValidEmail(email) {
  if (!email || typeof email !== "string") return false;
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim());
}

export function validateEmail(email) {
  return isValidEmail(email) ? null : "Please enter a valid email address";
}

/**
 * Validate password complexity:
 * At least 8 characters, 1 uppercase, 1 lowercase, 1 number, 1 special character
 */
export function validatePassword(password) {
  if (!password || password.length < 8) {
    return "Password must be at least 8 characters";
  }
  if (!/[A-Z]/.test(password)) {
    return "Password must contain at least one uppercase letter";
  }
  if (!/[a-z]/.test(password)) {
    return "Password must contain at least one lowercase letter";
  }
  if (!/[0-9]/.test(password)) {
    return "Password must contain at least one number";
  }
  if (!/[^A-Za-z0-9]/.test(password)) {
    return "Password must contain at least one special character";
  }
  return null;
}

/**
 * Validate phone number format (Kenyan or international)
 */
export function isValidPhone(phone) {
  if (!phone || typeof phone !== "string") return false;
  const clean = phone.replace(/[\s+\-()]/g, "");
  return /^(?:254|0)[17]\d{8}$/.test(clean) || /^\d{8,15}$/.test(clean);
}

/**
 * Validate positive number
 */
export function isPositiveNumber(val) {
  const num = Number(val);
  return !isNaN(num) && num > 0;
}

/**
 * Validate required fields
 */
export function validateForm(fields, rules) {
  const errors = {};
  for (const [field, rule] of Object.entries(rules)) {
    const value = fields[field];

    if (rule.required && (value === undefined || value === null || String(value).trim() === "")) {
      errors[field] = rule.label ? `${rule.label} is required` : `${field} is required`;
      continue;
    }

    if (value !== undefined && value !== null && String(value).trim() !== "") {
      if (rule.email && !isValidEmail(value)) {
        errors[field] = "Please enter a valid email address";
      } else if (rule.password) {
        const passErr = validatePassword(value);
        if (passErr) errors[field] = passErr;
      } else if (rule.phone && !isValidPhone(value)) {
        errors[field] = "Please enter a valid phone number (e.g., 0712345678 or +254712345678)";
      } else if (rule.positiveNumber && !isPositiveNumber(value)) {
        errors[field] = `${rule.label || field} must be a positive number`;
      } else if (rule.minLength && String(value).trim().length < rule.minLength) {
        errors[field] = `${rule.label || field} must be at least ${rule.minLength} characters`;
      } else if (rule.maxLength && String(value).trim().length > rule.maxLength) {
        errors[field] = `${rule.label || field} cannot exceed ${rule.maxLength} characters`;
      }
    }
  }
  return {
    isValid: Object.keys(errors).length === 0,
    errors,
  };
}
