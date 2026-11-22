import { DatabaseSync } from 'node:sqlite';
import { mkdirSync } from 'node:fs';
import { dirname } from 'node:path';

export function openDatabase(file) {
  if(file!==':memory:') mkdirSync(dirname(file), {recursive:true});
  const db=new DatabaseSync(file);
  db.exec(`PRAGMA foreign_keys=ON; PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000;
    CREATE TABLE IF NOT EXISTS companies(id INTEGER PRIMARY KEY,name TEXT NOT NULL);
    CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY,email TEXT NOT NULL UNIQUE,name TEXT NOT NULL,password TEXT NOT NULL,role TEXT NOT NULL CHECK(role IN ('admin','client')),company_id INTEGER REFERENCES companies(id),CHECK(role='admin' OR company_id IS NOT NULL));
    CREATE TABLE IF NOT EXISTS sessions(token TEXT PRIMARY KEY,user_id INTEGER NOT NULL REFERENCES users(id),csrf TEXT NOT NULL,expires INTEGER NOT NULL);
    CREATE TABLE IF NOT EXISTS records(id INTEGER PRIMARY KEY,company_id INTEGER NOT NULL REFERENCES companies(id),kind TEXT NOT NULL CHECK(kind IN ('channels','sales','payments','tasks','reports')),payload TEXT NOT NULL,version INTEGER NOT NULL DEFAULT 1,updated TEXT NOT NULL);
    CREATE INDEX IF NOT EXISTS records_company_kind ON records(company_id,kind);
    CREATE TABLE IF NOT EXISTS audit(id INTEGER PRIMARY KEY,user_id INTEGER REFERENCES users(id),company_id INTEGER REFERENCES companies(id),action TEXT NOT NULL,record_id INTEGER,before_value TEXT,after_value TEXT,created TEXT NOT NULL);
    CREATE TABLE IF NOT EXISTS jobs(id INTEGER PRIMARY KEY,company_id INTEGER NOT NULL REFERENCES companies(id),provider TEXT NOT NULL,state TEXT NOT NULL DEFAULT 'pending',attempts INTEGER NOT NULL DEFAULT 0,next_run INTEGER NOT NULL,error TEXT,UNIQUE(company_id,provider));
    CREATE TABLE IF NOT EXISTS login_attempts(key TEXT PRIMARY KEY,count INTEGER NOT NULL,reset_at INTEGER NOT NULL);
    PRAGMA user_version=1;`);
  return db;
}
export function transaction(db, action) {
  db.exec('BEGIN IMMEDIATE');
  try {const result=action(); db.exec('COMMIT');return result;} catch(error){db.exec('ROLLBACK');throw error;}
}
