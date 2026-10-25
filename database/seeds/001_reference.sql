INSERT INTO provinces (code, name, region_type) VALUES
    ('PB',  'Punjab',                     'province'),
    ('SD',  'Sindh',                      'province'),
    ('KP',  'Khyber Pakhtunkhwa',         'province'),
    ('BA',  'Balochistan',                'province'),
    ('ICT', 'Islamabad Capital Territory','territory'),
    ('GB',  'Gilgit-Baltistan',           'territory'),
    ('AJK', 'Azad Jammu and Kashmir',     'territory')
ON CONFLICT (code) DO NOTHING;

INSERT INTO permissions (code, description) VALUES
    ('applications.view',      'View application lists and full applications'),
    ('applications.review',    'Approve, reject or return applications; add review notes'),
    ('applications.export',    'Export application records to CSV'),
    ('pii.view',               'View personally identifiable student, parent and academic details'),
    ('eligibility.view',       'View eligibility status and criteria results'),
    ('eligibility.override',   'Create manual eligibility overrides'),
    ('eligibility.rules',      'Change eligibility rule configuration'),
    ('qr.manage',              'Revoke and replace QR codes'),
    ('reports.view',           'View aggregate reports and statistics'),
    ('audit.view',             'View audit logs'),
    ('users.manage',           'Manage administrator accounts')
ON CONFLICT (code) DO NOTHING;

INSERT INTO role_permissions (role, permission_code)
SELECT 'super_admin', code FROM permissions
ON CONFLICT DO NOTHING;

INSERT INTO role_permissions (role, permission_code) VALUES
    ('application_reviewer', 'applications.view'),
    ('application_reviewer', 'applications.review'),
    ('application_reviewer', 'pii.view'),
    ('application_reviewer', 'eligibility.view'),
    ('application_reviewer', 'reports.view'),
    ('report_viewer',        'reports.view')
ON CONFLICT DO NOTHING;

-- Rule version 1, from the published criteria. Both DOB bounds are inclusive.
INSERT INTO eligibility_rules (version, dob_min, dob_max, min_percentage, is_active, notes)
VALUES (1, DATE '2009-02-02', DATE '2012-02-02', 60.00, TRUE, 'Initial published criteria')
ON CONFLICT (version) DO NOTHING;
