-- New or reset administrator accounts get a temporary password and must choose their own at first login.
ALTER TABLE users ADD COLUMN must_change_password BOOLEAN NOT NULL DEFAULT FALSE;
