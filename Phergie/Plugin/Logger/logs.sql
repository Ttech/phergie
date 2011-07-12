CREATE TABLE logs (
        id INTEGER UNIQUE PRIMARY KEY,
        time INTEGER NOT NULL,
        location TEXT,
        type TEXT,
        user TEXT,
        content TEXT
);

