CREATE DATABASE maxs_guard;
CREATE TABLE maxs_guard.sentinel (identity VARCHAR(64) PRIMARY KEY);
INSERT INTO maxs_guard.sentinel VALUES ('maxs-isolated-v1');
GRANT SELECT ON maxs_guard.sentinel TO 'maxs_test'@'%';
