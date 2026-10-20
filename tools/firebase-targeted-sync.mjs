import fs from "node:fs";
import path from "node:path";
import { GoogleAuth } from "google-auth-library";

const serviceAccountPath = process.env.GOOGLE_APPLICATION_CREDENTIALS
  ? path.resolve(process.env.GOOGLE_APPLICATION_CREDENTIALS)
  : path.resolve("./serviceAccount.json");
const outputPath = path.resolve(process.env.FIREBASE_SYNC_FILE || "storage/app/firebase-targeted.ndjson");
const authPath = path.resolve(process.env.FIREBASE_AUTH_FILE || "storage/app/firebase-auth.ndjson");
const reportPath = `${outputPath}.report.json`;
const collections = ["users", "user_data", "status", "posts", "reels"];

const serviceAccount = JSON.parse(fs.readFileSync(serviceAccountPath, "utf8"));
const auth = new GoogleAuth({
  credentials: serviceAccount,
  scopes: ["https://www.googleapis.com/auth/datastore", "https://www.googleapis.com/auth/identitytoolkit"],
});
const client = await auth.getClient();
const token = (await client.getAccessToken()).token;
const base = `https://firestore.googleapis.com/v1/projects/${encodeURIComponent(serviceAccount.project_id)}/databases/(default)/documents`;

async function request(url) {
  for (let attempt = 0; ; attempt++) {
    const response = await fetch(url, {
      headers: { Authorization: `Bearer ${token}` },
      signal: AbortSignal.timeout(45000),
    });
    if (response.ok) return response.json();
    if (attempt < 3 && (response.status === 429 || response.status >= 500)) {
      await new Promise((resolve) => setTimeout(resolve, 1000 * 2 ** attempt));
      continue;
    }
    throw new Error(`Firebase export failed: HTTP ${response.status} (${url.pathname})`);
  }
}

function writeAtomic(file, content) {
  fs.mkdirSync(path.dirname(file), { recursive: true });
  const temporary = `${file}.${process.pid}.tmp`;
  fs.writeFileSync(temporary, content, { mode: 0o600 });
  fs.renameSync(temporary, file);
}

async function listAccounts() {
  const rows = [];
  let pageToken = "";
  do {
    const url = new URL(`https://identitytoolkit.googleapis.com/v1/projects/${encodeURIComponent(serviceAccount.project_id)}/accounts:batchGet`);
    url.searchParams.set("maxResults", "1000");
    if (pageToken) url.searchParams.set("nextPageToken", pageToken);
    const payload = await request(url);
    for (const user of payload.users || []) {
      // Password hashes are unnecessary for Firebase-token exchange.
      const { passwordHash, salt, passwordSalt, ...account } = user;
      rows.push(account);
    }
    pageToken = payload.nextPageToken || "";
  } while (pageToken);
  return rows;
}

function decodeValue(value) {
  if (value === null || value === undefined) return value;
  if (Object.hasOwn(value, "nullValue")) return null;
  if (Object.hasOwn(value, "booleanValue")) return value.booleanValue;
  if (Object.hasOwn(value, "stringValue")) return value.stringValue;
  if (Object.hasOwn(value, "integerValue")) return Number(value.integerValue);
  if (Object.hasOwn(value, "doubleValue")) return value.doubleValue;
  if (Object.hasOwn(value, "timestampValue")) return { _type: "timestamp", iso: value.timestampValue };
  if (Object.hasOwn(value, "referenceValue")) return { _type: "reference", path: value.referenceValue.split("/documents/")[1] || value.referenceValue };
  if (Object.hasOwn(value, "geoPointValue")) return { _type: "geopoint", latitude: value.geoPointValue.latitude, longitude: value.geoPointValue.longitude };
  if (Object.hasOwn(value, "bytesValue")) return { _type: "bytes", base64: value.bytesValue };
  if (Object.hasOwn(value, "arrayValue")) return (value.arrayValue.values || []).map(decodeValue);
  if (Object.hasOwn(value, "mapValue")) return decodeFields(value.mapValue.fields || {});
  return value;
}

function decodeFields(fields) {
  return Object.fromEntries(Object.entries(fields).sort(([a], [b]) => a.localeCompare(b, 'en')).map(([key, value]) => [key, decodeValue(value)]));
}

async function listCollection(collection) {
  const rows = [];
  let pageToken = "";
  do {
    const url = new URL(`${base}/${collection}`);
    url.searchParams.set("pageSize", "300");
    if (pageToken) url.searchParams.set("pageToken", pageToken);
    const payload = await request(url);
    for (const document of payload.documents || []) {
      const documentPath = document.name.split("/documents/")[1];
      rows.push({
        collectionPath: collection,
        documentId: documentPath.split("/").pop(),
        documentPath,
        parentDocumentPath: null,
        createTime: document.createTime || null,
        updateTime: document.updateTime || null,
        data: decodeFields(document.fields || {}),
      });
    }
    pageToken = payload.nextPageToken || "";
  } while (pageToken);
  return rows;
}

const rows = [];
for (const collection of collections) {
  const collectionRows = await listCollection(collection);
  rows.push(...collectionRows);
  console.log(`${collection}: ${collectionRows.length} documents`);
}

const accounts = await listAccounts();
console.log(`Firebase Auth: ${accounts.length} accounts`);
writeAtomic(authPath, accounts.map((row) => JSON.stringify(row)).join("\n") + (accounts.length ? "\n" : ""));
writeAtomic(outputPath, rows.map((row) => JSON.stringify(row)).join("\n") + (rows.length ? "\n" : ""));
writeAtomic(reportPath, JSON.stringify({ projectId: serviceAccount.project_id, collections: Object.fromEntries(collections.map((name) => [name, rows.filter((row) => row.collectionPath === name).length])), authAccounts: accounts.length, totalDocuments: rows.length, finishedAt: new Date().toISOString() }, null, 2));
console.log(`Targeted export: ${outputPath}`);
