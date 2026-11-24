-- Parse Admin role separation. Existing admins retain full privileges.
ALTER TABLE users MODIFY COLUMN role ENUM('user','admin','developer_admin','education_admin') NOT NULL DEFAULT 'user';
UPDATE users SET role='developer_admin' WHERE role='admin';
ALTER TABLE users MODIFY COLUMN role ENUM('user','developer_admin','education_admin') NOT NULL DEFAULT 'user';
