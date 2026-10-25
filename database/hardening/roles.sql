-- OPTIONAL but recommended for production: make the eligibility privacy rule a DATABASE rule,
-- not only an application rule. Run as a superuser. Replace the passwords first.
--
-- Plan: the student-facing part of the app connects as iha_student_app, which has NO access to
-- eligibility_*, review_notes, audit_logs or qr_verification_events. The admin portal and the
-- eligibility/QR services connect as iha_admin_app. A SQL injection or coding mistake in a
-- student page then cannot read eligibility data.

CREATE ROLE iha_student_app LOGIN PASSWORD 'CHANGE_ME_STUDENT';
CREATE ROLE iha_admin_app   LOGIN PASSWORD 'CHANGE_ME_ADMIN';

GRANT USAGE ON SCHEMA public TO iha_student_app, iha_admin_app;

-- Admin / system role: full DML (history tables are protected by append-only triggers).
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO iha_admin_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO iha_admin_app;

-- Student role: only what the student portal needs.
GRANT SELECT, INSERT, UPDATE ON
    email_verification_tokens, student_profiles, applications, academic_records,
    academic_subjects, parent_guardians, travel_responses, travel_history, notifications
TO iha_student_app;
-- users: students may never change their own role or status, so UPDATE is column-limited.
GRANT SELECT, INSERT ON users TO iha_student_app;
GRANT UPDATE (password_hash, last_login_at, email_verified_at, password_changed_at) ON users TO iha_student_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON password_reset_tokens, sessions TO iha_student_app;
-- Append-only audit trail: students' own actions can be logged but never read back.
GRANT INSERT ON audit_logs TO iha_student_app;
GRANT DELETE ON academic_subjects, travel_history TO iha_student_app;
GRANT INSERT ON auth_rate_limits TO iha_student_app;
GRANT SELECT ON auth_rate_limits, provinces, districts, cities TO iha_student_app;
-- Published criteria only (shown on the public landing page); no student results live in this table.
GRANT SELECT ON eligibility_rules TO iha_student_app;
-- Rate limiter prunes old rows; token cleanup on resend.
GRANT DELETE ON auth_rate_limits, email_verification_tokens TO iha_student_app;
GRANT SELECT (id, student_profile_id, application_id, status, created_at) ON student_qr_codes TO iha_student_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO iha_student_app;

-- Explicitly nothing on: eligibility_results, eligibility_overrides,
-- application_eligibility, application_reviews, review_notes, audit_logs,
-- qr_verification_events, permissions, role_permissions.
