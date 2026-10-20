import fs from 'node:fs';
import path from 'node:path';

// Read the token already used by the Firebase CLI. No credentials are written
// to the export file. The export is newline-delimited so it can be imported
// incrementally without loading the database into memory.
const projectId = process.argv[2] || 'celeone-e5843';
const output = process.argv[3] || path.resolve('storage/app/firebase-export.ndjson');
const tokenCandidates = [
  path.join(process.env.USERPROFILE || '', '.config', 'configstore', 'firebase-tools.json'),
  path.join(process.env.APPDATA || '', 'configstore', 'firebase-tools.json'),
];
const tokenFile = tokenCandidates.find((candidate) => fs.existsSync(candidate)) || tokenCandidates[0];
const firebaseConfig = JSON.parse(fs.readFileSync(tokenFile, 'utf8'));
const accessToken = firebaseConfig.tokens?.access_token;
if (!accessToken) throw new Error(`Firebase CLI access token not found at ${tokenFile}`);

const base = `https://firestore.googleapis.com/v1/projects/${projectId}/databases/(default)/documents`;
const headers = { Authorization: `Bearer ${accessToken}`, Accept: 'application/json' };
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function request(url, options = {}, attempt = 0) {
  try {
    const response = await fetch(url, {
      ...options,
      signal: AbortSignal.timeout(45_000),
      headers: { ...headers, ...(options.headers || {}) },
    });
    if (response.ok) return response.json();
    const body = await response.text();
    if ((response.status === 429 || response.status >= 500) && attempt < 7) {
      await sleep(750 * 2 ** attempt);
      return request(url, options, attempt + 1);
    }
    throw new Error(`${response.status} ${response.statusText}: ${body.slice(0, 500)}`);
  } catch (error) {
    if (attempt < 7 && (error?.code === 'ECONNRESET' || error?.code === 'ETIMEDOUT' || error?.name === 'TimeoutError' || error?.name === 'TypeError')) {
      const delay = 750 * 2 ** attempt;
      console.warn(`Firebase request failed (${error.code || error.name || 'network'}); retrying in ${delay}ms`);
      await sleep(delay);
      return request(url, options, attempt + 1);
    }
    throw error;
  }
}

function decodeValue(value) {
  if (!value || typeof value !== 'object') return value;
  if ('nullValue' in value) return null;
  if ('booleanValue' in value) return value.booleanValue;
  if ('integerValue' in value) return Number(value.integerValue);
  if ('doubleValue' in value) return value.doubleValue;
  if ('timestampValue' in value) return value.timestampValue;
  if ('stringValue' in value) return value.stringValue;
  if ('bytesValue' in value) return value.bytesValue;
  if ('referenceValue' in value) return value.referenceValue;
  if ('geoPointValue' in value) return value.geoPointValue;
  if ('arrayValue' in value) return (value.arrayValue.values || []).map(decodeValue);
  if ('mapValue' in value) return Object.fromEntries(Object.entries(value.mapValue.fields || {}).map(([key, item]) => [key, decodeValue(item)]));
  return value;
}

async function collectionIds(parent) {
  const ids = [];
  let pageToken = '';
  do {
    const url = `${base}${parent ? `/${parent}` : ''}:listCollectionIds?pageSize=1000${pageToken ? `&pageToken=${encodeURIComponent(pageToken)}` : ''}`;
    const result = await request(url, { method: 'POST', body: '{}' });
    ids.push(...(result.collectionIds || []));
    pageToken = result.nextPageToken || '';
  } while (pageToken);
  return ids;
}

async function documents(collectionPath) {
  const rows = [];
  let pageToken = '';
  do {
    const url = `${base}/${collectionPath}?pageSize=300${pageToken ? `&pageToken=${encodeURIComponent(pageToken)}` : ''}`;
    const result = await request(url);
    rows.push(...(result.documents || []));
    pageToken = result.nextPageToken || '';
  } while (pageToken);
  return rows;
}

const stream = fs.createWriteStream(output, { encoding: 'utf8' });
let count = 0;
async function walk(parentPath, relativeCollectionPath = '') {
  for (const collectionId of await collectionIds(parentPath)) {
    const collectionPath = relativeCollectionPath ? `${relativeCollectionPath}/${collectionId}` : collectionId;
    for (const document of await documents(collectionPath)) {
      const documentPath = document.name.split('/documents/')[1];
      const documentId = documentPath.split('/').at(-1);
      const parentDocumentPath = documentPath.split('/').slice(0, -1).join('/') || null;
      stream.write(JSON.stringify({
        collectionPath,
        documentId,
        parentDocumentPath,
        createTime: document.createTime || null,
        updateTime: document.updateTime || null,
        data: Object.fromEntries(Object.entries(document.fields || {}).map(([key, value]) => [key, decodeValue(value)])),
      }) + '\n');
      count += 1;
      if (count % 500 === 0) console.log(`exported ${count} documents`);
      await walk(documentPath, collectionPath);
    }
  }
}

fs.mkdirSync(path.dirname(output), { recursive: true });
await walk('');
await new Promise((resolve, reject) => { stream.end(resolve); stream.on('error', reject); });
console.log(`Firebase export complete: ${count} documents -> ${output}`);
