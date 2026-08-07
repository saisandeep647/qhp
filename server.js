// Simple production-minded Express server using SQLite (better-sqlite3).
// - Serves API on /api
// - Serves static site from ./public
// - Use env PORT to change port
const express = require('express');
const cors = require('cors');
const path = require('path');
const Database = require('better-sqlite3');
const { v4: uuidv4 } = require('uuid');

const PORT = process.env.PORT || 3000;
const DB_FILE = process.env.DB_FILE || 'data.db';
const db = new Database(DB_FILE);

// Initialize DB schema
db.exec(`
CREATE TABLE IF NOT EXISTS entries (
  id TEXT PRIMARY KEY,
  category TEXT NOT NULL,
  businessName TEXT NOT NULL,
  ownerName TEXT,
  location TEXT,
  personalPhone TEXT,
  businessPhone TEXT,
  verified INTEGER DEFAULT 0,
  status TEXT DEFAULT 'pending',
  rejectReason TEXT,
  createdAt TEXT,
  updatedAt TEXT
);
`);

// Prepared statements
const insertStmt = db.prepare(`INSERT INTO entries
  (id, category, businessName, ownerName, location, personalPhone, businessPhone, verified, status, rejectReason, createdAt, updatedAt)
  VALUES (@id,@category,@businessName,@ownerName,@location,@personalPhone,@businessPhone,@verified,@status,@rejectReason,@createdAt,@updatedAt)
`);
const selectAll = db.prepare(`SELECT * FROM entries ORDER BY createdAt DESC`);
const selectFiltered = db.prepare(`SELECT * FROM entries WHERE status = ? ORDER BY createdAt DESC`);
const selectById = db.prepare(`SELECT * FROM entries WHERE id = ?`);
const updateStmt = db.prepare(`UPDATE entries SET
  category=@category, businessName=@businessName, ownerName=@ownerName, location=@location,
  personalPhone=@personalPhone, businessPhone=@businessPhone, verified=@verified, status=@status,
  rejectReason=@rejectReason, updatedAt=@updatedAt
  WHERE id=@id
`);
const patchStmt = db.prepare(`UPDATE entries SET {setClause} WHERE id = @id`); // placeholder usage shown below
const deleteStmt = db.prepare(`DELETE FROM entries WHERE id = ?`);

const app = express();
app.use(cors());
app.use(express.json());
app.use(express.urlencoded({ extended: false }));

// Serve frontend
app.use(express.static(path.join(__dirname, 'public')));

// Helper: basic validation
function validateEntryInput(body) {
  if (!body.category || typeof body.category !== 'string') return 'Missing category';
  if (!body.businessName || typeof body.businessName !== 'string') return 'Missing businessName';
  return null;
}

// GET /api/entries?status=accepted|pending|rejected
app.get('/api/entries', (req, res) => {
  const status = req.query.status;
  try {
    const rows = status ? selectFiltered.all(status) : selectAll.all();
    res.json(rows);
  } catch (err) {
    console.error(err);
    res.status(500).json({ error: 'DB error' });
  }
});

// GET /api/entries/:id
app.get('/api/entries/:id', (req, res) => {
  const row = selectById.get(req.params.id);
  if (!row) return res.status(404).json({ error: 'Not found' });
  res.json(row);
});

// POST /api/entries  -> create
app.post('/api/entries', (req, res) => {
  const err = validateEntryInput(req.body);
  if (err) return res.status(400).json({ error: err });

  const now = new Date().toISOString();
  const record = {
    id: uuidv4(),
    category: req.body.category,
    businessName: req.body.businessName,
    ownerName: req.body.ownerName || null,
    location: req.body.location || null,
    personalPhone: req.body.personalPhone || null,
    businessPhone: req.body.businessPhone || null,
    verified: req.body.verified ? 1 : 0,
    status: req.body.status || 'pending',
    rejectReason: req.body.rejectReason || null,
    createdAt: now,
    updatedAt: now
  };
  try {
    insertStmt.run(record);
    res.status(201).json({ id: record.id });
  } catch (err) {
    console.error(err);
    res.status(500).json({ error: 'Insert failed' });
  }
});

// PUT /api/entries/:id -> replace/update (edit fields)
app.put('/api/entries/:id', (req, res) => {
  const id = req.params.id;
  const existing = selectById.get(id);
  if (!existing) return res.status(404).json({ error: 'Not found' });

  const payload = {
    id,
    category: req.body.category || existing.category,
    businessName: req.body.businessName || existing.businessName,
    ownerName: req.body.ownerName !== undefined ? req.body.ownerName : existing.ownerName,
    location: req.body.location !== undefined ? req.body.location : existing.location,
    personalPhone: req.body.personalPhone !== undefined ? req.body.personalPhone : existing.personalPhone,
    businessPhone: req.body.businessPhone !== undefined ? req.body.businessPhone : existing.businessPhone,
    verified: req.body.verified !== undefined ? (req.body.verified ? 1 : 0) : existing.verified,
    status: req.body.status || existing.status,
    rejectReason: req.body.rejectReason !== undefined ? req.body.rejectReason : existing.rejectReason,
    updatedAt: new Date().toISOString()
  };
  try {
    updateStmt.run(payload);
    res.json({ ok: true });
  } catch (err) {
    console.error(err);
    res.status(500).json({ error: 'Update failed' });
  }
});

// POST /api/entries/:id/verify  -> Save ownerName + personalPhone and set verified flag (still hidden until accepted)
app.post('/api/entries/:id/verify', (req, res) => {
  const id = req.params.id;
  const { ownerName, personalPhone } = req.body;
  if (!ownerName || !personalPhone) return res.status(400).json({ error: 'ownerName and personalPhone required' });

  const existing = selectById.get(id);
  if (!existing) return res.status(404).json({ error: 'Not found' });

  const stmt = db.prepare(`UPDATE entries SET ownerName = @ownerName, personalPhone = @personalPhone, verified = 1, updatedAt = @updatedAt WHERE id = @id`);
  try {
    stmt.run({
      ownerName,
      personalPhone,
      updatedAt: new Date().toISOString(),
      id
    });
    res.json({ ok: true });
  } catch (err) {
    console.error(err);
    res.status(500).json({ error: 'Verify failed' });
  }
});

// POST /api/entries/:id/accept  -> set status accepted (after accept, ownerName & personalPhone become visible)
app.post('/api/entries/:id/accept', (req, res) => {
  const id = req.params.id;
  const existing = selectById.get(id);
  if (!existing) return res.status(404).json({ error: 'Not found' });

  const stmt = db.prepare(`UPDATE entries SET status = 'accepted', rejectReason = NULL, updatedAt = @updatedAt WHERE id = @id`);
  try {
    stmt.run({ updatedAt: new Date().toISOString(), id });
    res.json({ ok: true });
  } catch (err) {
    console.error(err);
    res.status(500).json({ error: 'Accept failed' });
  }
});

// POST /api/entries/:id/reject  -> set status rejected + reason
app.post('/api/entries/:id/reject', (req, res) => {
  const id = req.params.id;
  const reason = req.body.reason;
  if (!reason || typeof reason !== 'string') return res.status(400).json({ error: 'Reject reason required' });

  const existing = selectById.get(id);
  if (!existing) return res.status(404).json({ error: 'Not found' });

  const stmt = db.prepare(`UPDATE entries SET status = 'rejected', rejectReason = @reason, updatedAt = @updatedAt WHERE id = @id`);
  try {
    stmt.run({ reason, updatedAt: new Date().toISOString(), id });
    res.json({ ok: true });
  } catch (err) {
    console.error(err);
    res.status(500).json({ error: 'Reject failed' });
  }
});

// DELETE
app.delete('/api/entries/:id', (req, res) => {
  try {
    deleteStmt.run(req.params.id);
    res.json({ ok: true });
  } catch (err) {
    res.status(500).json({ error: 'Delete failed' });
  }
});

// Export all entries
app.get('/api/export', (req, res) => {
  const rows = selectAll.all();
  res.setHeader('Content-Disposition', 'attachment; filename=entries.json');
  res.json(rows);
});

// Import entries (replace semantics: we will insert new with new ids if missing)
app.post('/api/import', (req, res) => {
  const arr = req.body;
  if (!Array.isArray(arr)) return res.status(400).json({ error: 'Expected array' });
  const now = new Date().toISOString();
  const insert = db.prepare(`INSERT OR REPLACE INTO entries
    (id, category, businessName, ownerName, location, personalPhone, businessPhone, verified, status, rejectReason, createdAt, updatedAt)
    VALUES (@id,@category,@businessName,@ownerName,@location,@personalPhone,@businessPhone,@verified,@status,@rejectReason,@createdAt,@updatedAt)
  `);
  const tx = db.transaction((items) => {
    for (const it of items) {
      const id = it.id || uuidv4();
      insert.run({
        id,
        category: it.category || '',
        businessName: it.businessName || '',
        ownerName: it.ownerName || null,
        location: it.location || null,
        personalPhone: it.personalPhone || null,
        businessPhone: it.businessPhone || null,
        verified: it.verified ? 1 : 0,
        status: it.status || 'pending',
        rejectReason: it.rejectReason || null,
        createdAt: it.createdAt || now,
        updatedAt: it.updatedAt || now
      });
    }
  });
  try {
    tx(arr);
    res.json({ imported: arr.length });
  } catch (err) {
    console.error(err);
    res.status(500).json({ error: 'Import failed' });
  }
});

app.listen(PORT, () => {
  console.log(`Server listening on port ${PORT}`);
});
