-- Fixture patients (pid 1–6). Each insert runs only if that pid is not already present.
-- See which fixture pids already exist:
SELECT pid, fname, lname, email
FROM patient_data
WHERE pid BETWEEN 1 AND 6
ORDER BY pid;

-- pid 1
SELECT EXISTS(SELECT 1 FROM patient_data WHERE pid = 1) AS patient_1_exists;
INSERT INTO patient_data (
    fname, lname, mname, DOB, sex,
    street, city, state, postal_code, country_code,
    phone_home, email,
    pid, pubpid, `date`, regdate, language, status, pricelevel
)
SELECT
    'Sam', 'Testperson', 'Q', '1985-03-15', 'Male',
    '123 Demo Lane', 'Boston', 'MA', '02118', 'USA',
    '555-0100', 'sam.testperson@example.invalid',
    1, '1', NOW(), NOW(), 'english', 'active', 'standard'
FROM (SELECT 1 AS _d) AS _
WHERE NOT EXISTS (SELECT 1 FROM patient_data p WHERE p.pid = 1);

-- pid 2
SELECT EXISTS(SELECT 1 FROM patient_data WHERE pid = 2) AS patient_2_exists;
INSERT INTO patient_data (
    fname, lname, mname, DOB, sex,
    street, city, state, postal_code, country_code,
    phone_home, email,
    pid, pubpid, `date`, regdate, language, status, pricelevel
)
SELECT
    'Alex', 'Sample', '', '1990-07-20', 'Female',
    '456 Example St', 'Cambridge', 'MA', '02139', 'USA',
    '555-0200', 'alex.sample@example.invalid',
    2, '2', NOW(), NOW(), 'english', 'active', 'standard'
FROM (SELECT 1 AS _d) AS _
WHERE NOT EXISTS (SELECT 1 FROM patient_data p WHERE p.pid = 2);

-- pid 3
SELECT EXISTS(SELECT 1 FROM patient_data WHERE pid = 3) AS patient_3_exists;
INSERT INTO patient_data (
    fname, lname, mname, DOB, sex,
    street, city, state, postal_code, country_code,
    phone_home, email,
    pid, pubpid, `date`, regdate, language, status, pricelevel
)
SELECT
    'Jordan', 'Rivers', 'L', '1978-11-02', 'Male',
    '78 River Rd', 'Somerville', 'MA', '02144', 'USA',
    '555-0300', 'jordan.rivers@example.invalid',
    3, '3', NOW(), NOW(), 'english', 'active', 'standard'
FROM (SELECT 1 AS _d) AS _
WHERE NOT EXISTS (SELECT 1 FROM patient_data p WHERE p.pid = 3);

-- pid 4
SELECT EXISTS(SELECT 1 FROM patient_data WHERE pid = 4) AS patient_4_exists;
INSERT INTO patient_data (
    fname, lname, mname, DOB, sex,
    street, city, state, postal_code, country_code,
    phone_home, email,
    pid, pubpid, `date`, regdate, language, status, pricelevel
)
SELECT
    'Riley', 'Chen', '', '2001-04-18', 'Female',
    '200 Beacon St', 'Boston', 'MA', '02116', 'USA',
    '555-0400', 'riley.chen@example.invalid',
    4, '4', NOW(), NOW(), 'english', 'active', 'standard'
FROM (SELECT 1 AS _d) AS _
WHERE NOT EXISTS (SELECT 1 FROM patient_data p WHERE p.pid = 4);

-- pid 5
SELECT EXISTS(SELECT 1 FROM patient_data WHERE pid = 5) AS patient_5_exists;
INSERT INTO patient_data (
    fname, lname, mname, DOB, sex,
    street, city, state, postal_code, country_code,
    phone_home, email,
    pid, pubpid, `date`, regdate, language, status, pricelevel
)
SELECT
    'Morgan', 'Blake', 'J', '1962-09-30', 'UNK',
    '5 Oak Ave', 'Brookline', 'MA', '02445', 'USA',
    '555-0500', 'morgan.blake@example.invalid',
    5, '5', NOW(), NOW(), 'english', 'active', 'standard'
FROM (SELECT 1 AS _d) AS _
WHERE NOT EXISTS (SELECT 1 FROM patient_data p WHERE p.pid = 5);

-- pid 6
SELECT EXISTS(SELECT 1 FROM patient_data WHERE pid = 6) AS patient_6_exists;
INSERT INTO patient_data (
    fname, lname, mname, DOB, sex,
    street, city, state, postal_code, country_code,
    phone_home, email,
    pid, pubpid, `date`, regdate, language, status, pricelevel
)
SELECT
    'Casey', 'Nunez', 'R', '1995-01-08', 'Female',
    '60 Harbor Dr', 'Quincy', 'MA', '02169', 'USA',
    '555-0600', 'casey.nunez@example.invalid',
    6, '6', NOW(), NOW(), 'english', 'active', 'standard'
FROM (SELECT 1 AS _d) AS _
WHERE NOT EXISTS (SELECT 1 FROM patient_data p WHERE p.pid = 6);

-- After running inserts, confirm fixture rows:
SELECT id, pid, fname, lname, DOB, sex, email
FROM patient_data
WHERE pid BETWEEN 1 AND 6
ORDER BY pid;
