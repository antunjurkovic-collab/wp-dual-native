#!/usr/bin/env node
import axios, { AxiosInstance } from 'axios';
import dotenv from 'dotenv';
import path from 'path';
import { fileURLToPath } from 'url';
import { z } from 'zod';
import { createHash } from 'node:crypto';
import { Server } from '@modelcontextprotocol/sdk/server/index.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { CallToolRequestSchema, ListToolsRequestSchema } from '@modelcontextprotocol/sdk/types.js';

// Load .env if present (optional)
const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
dotenv.config({ path: path.join(__dirname, '..', '.env') });

// Config
const WP_URL = process.env.WP_URL ?? '';
const WP_USER = process.env.WP_USER ?? '';
const WP_PASSWORD = process.env.WP_PASSWORD ?? '';
if (!WP_URL || !WP_USER || !WP_PASSWORD) {
  console.error('Missing WP_URL, WP_USER, or WP_PASSWORD in environment.');
}

// Clients
const dual: AxiosInstance = axios.create({
  baseURL: `${WP_URL.replace(/\/$/, '')}/wp-json/dual-native/v1`,
  auth: { username: WP_USER, password: WP_PASSWORD },
  headers: { 'Content-Type': 'application/json' },
  validateStatus: () => true,
});
const wp: AxiosInstance = axios.create({
  baseURL: `${WP_URL.replace(/\/$/, '')}/wp-json`,
  auth: { username: WP_USER, password: WP_PASSWORD },
  headers: { 'Content-Type': 'application/json' },
  validateStatus: () => true,
});

// ETag caches
type CacheEntry<T> = { etag: string; body: T };
const mrCache = new Map<number, CacheEntry<any>>();
const mdCache = new Map<number, CacheEntry<string>>();

async function getMR(id: number) {
  const cached = mrCache.get(id);
  const headers: Record<string, string> = {};
  if (cached?.etag) headers['If-None-Match'] = `"${cached.etag}"`;
  const res = await dual.get(`/posts/${id}`, { headers });
  if (res.status === 304 && cached) return { fromCache: true, etag: cached.etag, body: cached.body };
  if (res.status >= 200 && res.status < 300) {
    const etag = (res.headers['etag'] || '').replace(/^W\//, '').replace(/^"|"$/g, '');
    const body = res.data; if (etag) mrCache.set(id, { etag, body });
    return { fromCache: false, etag, body };
  }
  throw new Error(`MR request failed: HTTP ${res.status}`);
}

async function getMD(id: number) {
  const cached = mdCache.get(id);
  const headers: Record<string, string> = { Accept: 'text/markdown' };
  if (cached?.etag) headers['If-None-Match'] = `"${cached.etag}"`;
  const res = await dual.get(`/posts/${id}/md`, { headers, responseType: 'text' });
  if (res.status === 304 && cached) return { fromCache: true, etag: cached.etag, body: cached.body };
  if (res.status >= 200 && res.status < 300) {
    const etag = (res.headers['etag'] || '').replace(/^W\//, '').replace(/^"|"$/g, '');
    const body: string = res.data as any; if (etag) mdCache.set(id, { etag, body });
    return { fromCache: false, etag, body };
  }
  throw new Error(`Markdown request failed: HTTP ${res.status}`);
}

// MCP server
const server = new Server({ name: 'wp-dual-native', version: '1.0.0' }, { capabilities: { tools: {} } });

// Schemas
const ReadById = z.object({ id: z.number().int().positive() });
const BlockSchema = z.object({
  type: z.enum(['core/paragraph', 'core/heading', 'core/list']).default('core/paragraph'),
  content: z.string().optional(),
  level: z.number().int().min(1).max(6).optional(),
  ordered: z.boolean().optional(),
  items: z.array(z.string()).optional(),
});
const AppendBlock = z.object({ id: z.number().int().positive(), content: z.string().min(1), type: z.enum(['core/paragraph','core/heading']).optional().default('core/paragraph'), level: z.number().int().min(1).max(6).optional(), if_match: z.string().optional(), force: z.boolean().optional().default(false) });
const AppendBlocks = z.object({ id: z.number().int().positive(), blocks: z.array(BlockSchema).min(1), if_match: z.string().optional(), force: z.boolean().optional().default(false) });
const InsertAtIndex = z.object({ id: z.number().int().positive(), index: z.number().int().min(0), block: BlockSchema, if_match: z.string().optional(), force: z.boolean().optional().default(false) });
const InsertBlocksAtIndex = z.object({ id: z.number().int().positive(), index: z.number().int().min(0), blocks: z.array(BlockSchema).min(1), if_match: z.string().optional(), force: z.boolean().optional().default(false) });
const ApplyExcerpt = z.object({ id: z.number().int().positive(), excerpt: z.string().min(1) });
const CreatePost = z.object({ title: z.string().min(1), status: z.enum(['draft','publish','private','future']).optional().default('draft') });
const SetTitle = z.object({ id: z.number().int().positive(), title: z.string().min(1) });
const SetSlug = z.object({ id: z.number().int().positive(), slug: z.string().min(1) });
const SetStatus = z.object({ id: z.number().int().positive(), status: z.enum(['draft','publish','private','future']) });
const SetCategories = z.object({ id: z.number().int().positive(), categories: z.array(z.number().int().positive()) });
const SetTags = z.object({ id: z.number().int().positive(), tags: z.array(z.number().int().positive()) });
const SetFeaturedImage = z.object({ id: z.number().int().positive(), mediaId: z.number().int().positive() });
const SetTagsByNames = z.object({ id: z.number().int().positive(), names: z.array(z.string().min(1)).min(1) });
const SetCategoriesByNames = z.object({ id: z.number().int().positive(), names: z.array(z.string().min(1)).min(1) });
const SelfTest = z.object({ id: z.number().int().positive(), public: z.boolean().optional(), exclude: z.string().optional() });
const UpdateTitle = z.object({ id: z.number().int().positive(), title: z.string().min(1), if_match: z.string().optional() });

// Helpers
function canonicalize(obj: any): any { if (Array.isArray(obj)) return obj.map(canonicalize); if (obj && typeof obj === 'object') { const out: any = {}; for (const k of Object.keys(obj).sort()) out[k] = canonicalize(obj[k]); return out; } return obj; }
function computeCidLocal(mr: any, excludeKeys: string[]): string { const clean: any = {}; for (const k of Object.keys(mr)) if (!excludeKeys.includes(k)) clean[k] = mr[k]; const canon = canonicalize(clean); const json = JSON.stringify(canon); const h = createHash('sha256').update(json, 'utf8').digest('hex'); return `sha256-${h}`; }

server.setRequestHandler(ListToolsRequestSchema, async () => ({
  tools: [
    { name: 'list_posts', description: 'List recent posts (rid, cid, title, modified, status).', inputSchema: { type: 'object', properties: {} } },
    { name: 'read_mr', description: 'Read MR JSON for a post (ETag-aware).', inputSchema: { type: 'object', properties: { id: { type: 'number' } }, required: ['id'] } },
    { name: 'read_md', description: 'Read Markdown MR for a post (ETag-aware).', inputSchema: { type: 'object', properties: { id: { type: 'number' } }, required: ['id'] } },
    { name: 'ai_suggest', description: 'Get summary and tags from the suggest endpoint.', inputSchema: { type: 'object', properties: { id: { type: 'number' } }, required: ['id'] } },
    { name: 'append_block', description: 'Append a core block (paragraph/heading).', inputSchema: { type: 'object', properties: { id: { type: 'number' }, content: { type: 'string' }, type: { type: 'string', enum: ['core/paragraph','core/heading'] }, level: { type: 'number' } }, required: ['id','content'] } },
    { name: 'append_blocks', description: 'Append multiple blocks (batch).', inputSchema: { type: 'object', properties: { id: { type: 'number' }, blocks: { type: 'array' } }, required: ['id','blocks'] } },
    { name: 'insert_at_index', description: 'Insert a block at top-level index.', inputSchema: { type: 'object', properties: { id: { type: 'number' }, index: { type: 'number' }, block: { type: 'object' } }, required: ['id','index','block'] } },
    { name: 'insert_blocks_at_index', description: 'Insert multiple blocks at a top-level index (batch).', inputSchema: { type: 'object', properties: { id: { type: 'number' }, index: { type: 'number' }, blocks: { type: 'array' } }, required: ['id','index','blocks'] } },
    { name: 'apply_excerpt', description: 'Set the post excerpt.', inputSchema: { type: 'object', properties: { id: { type: 'number' }, excerpt: { type: 'string' } }, required: ['id','excerpt'] } },
    { name: 'create_post', description: 'Create a new post (defaults to draft).', inputSchema: { type: 'object', properties: { title: { type: 'string' }, status: { type: 'string' } }, required: ['title'] } },
    { name: 'set_title', description: 'Update post title.', inputSchema: { type: 'object', properties: { id: { type: 'number' }, title: { type: 'string' } }, required: ['id','title'] } },
    { name: 'set_slug', description: 'Update post slug.', inputSchema: { type: 'object', properties: { id: { type: 'number' }, slug: { type: 'string' } }, required: ['id','slug'] } },
    { name: 'set_status', description: 'Update post status.', inputSchema: { type: 'object', properties: { id: { type: 'number' }, status: { type: 'string' } }, required: ['id','status'] } },
    { name: 'set_categories', description: 'Set categories by IDs (overwrites).', inputSchema: { type: 'object', properties: { id: { type: 'number' }, categories: { type: 'array' } }, required: ['id','categories'] } },
    { name: 'set_tags', description: 'Set tags by IDs (overwrites).', inputSchema: { type: 'object', properties: { id: { type: 'number' }, tags: { type: 'array' } }, required: ['id','tags'] } },
    { name: 'set_featured_image', description: 'Set featured image by media ID.', inputSchema: { type: 'object', properties: { id: { type: 'number' }, mediaId: { type: 'number' } }, required: ['id','mediaId'] } },
    { name: 'set_tags_by_names', description: 'Ensure tags exist by name and set them.', inputSchema: { type: 'object', properties: { id: { type: 'number' }, names: { type: 'array' } }, required: ['id','names'] } },
    { name: 'set_categories_by_names', description: 'Ensure categories exist by name and set them.', inputSchema: { type: 'object', properties: { id: { type: 'number' }, names: { type: 'array' } }, required: ['id','names'] } },
    { name: 'update_title', description: 'Update the post title safely. Provide if_match (ETag) for optimistic locking.', inputSchema: { type: 'object', properties: { id: { type: 'number' }, title: { type: 'string' }, if_match: { type: 'string' } }, required: ['id','title'] } },
    { name: 'self_test', description: 'Validate MR structure, ETag/CID parity, 304s, and Content‑Digest parity (no writes).', inputSchema: { type: 'object', properties: { id: { type: 'number' }, public: { type: 'boolean' }, exclude: { type: 'string' } }, required: ['id'] } }
  ]
}));

server.setRequestHandler(CallToolRequestSchema, async (req) => {
  const { name, arguments: raw } = req.params;
  try {
    if (name === 'list_posts') { const res = await dual.get('/catalog'); if (res.status>=200&&res.status<300) return { content:[{type:'text',text:JSON.stringify(res.data,null,2)}]}; throw new Error(`Catalog failed: HTTP ${res.status}`); }
    if (name === 'read_mr') { const { id } = ReadById.parse(raw); const { fromCache, etag, body } = await getMR(id); const h = fromCache?'[MR cached]':'[MR fresh]'; return { content:[{type:'text',text:`${h} ETag: ${etag}\n`+JSON.stringify(body,null,2)}]}; }
    if (name === 'read_md') { const { id } = ReadById.parse(raw); const { fromCache, etag, body } = await getMD(id); const h = fromCache?'[MD cached]':'[MD fresh]'; return { content:[{type:'text',text:`${h} ETag: ${etag}\n\n${body}`}]}; }
    if (name === 'ai_suggest') { const { id } = ReadById.parse(raw); const r = await dual.get(`/posts/${id}/ai/suggest`); if (r.status>=200&&r.status<300) return { content:[{type:'text',text:JSON.stringify(r.data,null,2)}]}; throw new Error(`ai/suggest failed: HTTP ${r.status}`); }

    const autoIfMatch = (id:number, ifm?:string, force?:boolean) => { const cached = mrCache.get(id); if (ifm) return ifm; if (!force && cached?.etag) return `"${cached.etag}"`; return undefined; };

    if (name === 'append_block') { const { id, content, type, level, if_match, force } = AppendBlock.parse(raw); const block:any={type,content}; if (type==='core/heading') block.level=level??2; const headers:any={}; const eff=autoIfMatch(id, if_match, force); if (eff) headers['If-Match']=eff; const res=await dual.post(`/posts/${id}/blocks`,{insert:'append',blocks:[block]},{headers}); if(res.status>=200&&res.status<300){ try{await getMR(id);}catch{} const h=res.headers||{} as any; const before=h['x-dni-top-level-count-before']??''; const insertedAt=h['x-dni-inserted-at']??''; const after=h['x-dni-top-level-count']??''; return { content:[{type:'text',text:`Appended. New CID: ${res.data?.cid||'(unknown)'}\nTop-level blocks: ${before} -> ${after} (inserted at ${insertedAt})`}]}; } const msg=res.status===412?'Precondition Failed (If-Match did not match current CID)':`append_block failed: HTTP ${res.status}`; throw new Error(msg); }
    if (name === 'append_blocks') { const { id, blocks, if_match, force } = AppendBlocks.parse(raw); const headers:any={}; const eff=autoIfMatch(id, if_match, force); if (eff) headers['If-Match']=eff; const res=await dual.post(`/posts/${id}/blocks`,{insert:'append',blocks},{headers}); if(res.status>=200&&res.status<300){ try{await getMR(id);}catch{} const h=res.headers||{} as any; const before=h['x-dni-top-level-count-before']??''; const insertedAt=h['x-dni-inserted-at']??''; const after=h['x-dni-top-level-count']??''; return { content:[{type:'text',text:`Appended ${blocks.length} block(s). New CID: ${res.data?.cid||'(unknown)'}\nTop-level blocks: ${before} -> ${after} (inserted at ${insertedAt})`}]}; } const msg=res.status===412?'Precondition Failed (If-Match did not match current CID)':`append_blocks failed: HTTP ${res.status}`; throw new Error(msg); }
    if (name === 'insert_at_index') { const { id, index, block, if_match, force } = InsertAtIndex.parse(raw); const headers:any={}; const eff=autoIfMatch(id, if_match, force); if (eff) headers['If-Match']=eff; const res=await dual.post(`/posts/${id}/blocks`,{insert:'index',index,blocks:[block]},{headers}); if(res.status>=200&&res.status<300){ try{await getMR(id);}catch{} const h=res.headers||{} as any; const before=h['x-dni-top-level-count-before']??''; const insertedAt=h['x-dni-inserted-at']??index; const after=h['x-dni-top-level-count']??''; return { content:[{type:'text',text:`Inserted at ${insertedAt}. New CID: ${res.data?.cid||'(unknown)'}\nTop-level blocks: ${before} -> ${after}`}]}; } const msg=res.status===412?'Precondition Failed (If-Match did not match current CID)':`insert_at_index failed: HTTP ${res.status}`; throw new Error(msg); }
    if (name === 'insert_blocks_at_index') { const { id, index, blocks, if_match, force } = InsertBlocksAtIndex.parse(raw); const headers:any={}; const eff=autoIfMatch(id, if_match, force); if (eff) headers['If-Match']=eff; const res=await dual.post(`/posts/${id}/blocks`,{insert:'index',index,blocks},{headers}); if(res.status>=200&&res.status<300){ try{await getMR(id);}catch{} const h=res.headers||{} as any; const before=h['x-dni-top-level-count-before']??''; const insertedAt=h['x-dni-inserted-at']??index; const after=h['x-dni-top-level-count']??''; return { content:[{type:'text',text:`Inserted ${blocks.length} block(s) at ${insertedAt}. New CID: ${res.data?.cid||'(unknown)'}\nTop-level blocks: ${before} -> ${after}`}]}; } const msg=res.status===412?'Precondition Failed (If-Match did not match current CID)':`insert_blocks_at_index failed: HTTP ${res.status}`; throw new Error(msg); }

    if (name === 'apply_excerpt') { const { id, excerpt } = ApplyExcerpt.parse(raw); const res=await wp.post(`/wp/v2/posts/${id}`,{excerpt}); if(res.status>=200&&res.status<300) return { content:[{type:'text',text:'Excerpt applied.'}]}; throw new Error(`apply_excerpt failed: HTTP ${res.status}`); }
    if (name === 'create_post') { const { title, status } = CreatePost.parse(raw); const res=await wp.post('/wp/v2/posts',{title,status}); if(res.status>=200&&res.status<300){ const pid=res.data?.id; return { content:[{type:'text',text:`Created post id=${pid}`}]}; } throw new Error(`create_post failed: HTTP ${res.status}`); }
    if (name === 'set_title') { const { id, title } = SetTitle.parse(raw); const res=await wp.post(`/wp/v2/posts/${id}`,{title}); if(res.status>=200&&res.status<300) return { content:[{type:'text',text:'Title updated.'}]}; throw new Error(`set_title failed: HTTP ${res.status}`); }
    if (name === 'set_slug') { const { id, slug } = SetSlug.parse(raw); const res=await wp.post(`/wp/v2/posts/${id}`,{slug}); if(res.status>=200&&res.status<300) return { content:[{type:'text',text:'Slug updated.'}]}; throw new Error(`set_slug failed: HTTP ${res.status}`); }
    if (name === 'set_status') { const { id, status } = SetStatus.parse(raw); const res=await wp.post(`/wp/v2/posts/${id}`,{status}); if(res.status>=200&&res.status<300) return { content:[{type:'text',text:'Status updated.'}]}; throw new Error(`set_status failed: HTTP ${res.status}`); }
    if (name === 'set_categories') { const { id, categories } = SetCategories.parse(raw); const res=await wp.post(`/wp/v2/posts/${id}`,{categories}); if(res.status>=200&&res.status<300) return { content:[{type:'text',text:'Categories updated.'}]}; throw new Error(`set_categories failed: HTTP ${res.status}`); }
    if (name === 'set_tags') { const { id, tags } = SetTags.parse(raw); const res=await wp.post(`/wp/v2/posts/${id}`,{tags}); if(res.status>=200&&res.status<300) return { content:[{type:'text',text:'Tags updated.'}]}; throw new Error(`set_tags failed: HTTP ${res.status}`); }
    if (name === 'set_featured_image') { const { id, mediaId } = SetFeaturedImage.parse(raw); const res=await wp.post(`/wp/v2/posts/${id}`,{featured_media:mediaId}); if(res.status>=200&&res.status<300) return { content:[{type:'text',text:'Featured image set.'}]}; throw new Error(`set_featured_image failed: HTTP ${res.status}`); }
    if (name === 'set_tags_by_names') { const { id, names } = SetTagsByNames.parse(raw); const ids:number[]=[]; for (const nm of names){ const q=await wp.get('/wp/v2/tags',{params:{search:nm,per_page:100,_fields:'id,name'}}); let hit=Array.isArray(q.data)?(q.data as any[]).find(t=>String(t.name).toLowerCase()===nm.toLowerCase()):null; if(!hit){ const cr=await wp.post('/wp/v2/tags',{name:nm}); if(cr.status>=200&&cr.status<300) hit=cr.data; else throw new Error(`create tag failed: ${nm}`);} ids.push(hit.id);} const res=await wp.post(`/wp/v2/posts/${id}`,{tags:ids}); if(res.status>=200&&res.status<300) return { content:[{type:'text',text:`Tags set: ${ids.join(',')}`}]}; throw new Error(`set_tags_by_names failed: HTTP ${res.status}`); }
    if (name === 'set_categories_by_names') { const { id, names } = SetCategoriesByNames.parse(raw); const ids:number[]=[]; for (const nm of names){ const q=await wp.get('/wp/v2/categories',{params:{search:nm,per_page:100,_fields:'id,name'}}); let hit=Array.isArray(q.data)?(q.data as any[]).find(t=>String(t.name).toLowerCase()===nm.toLowerCase()):null; if(!hit){ const cr=await wp.post('/wp/v2/categories',{name:nm}); if(cr.status>=200&&cr.status<300) hit=cr.data; else throw new Error(`create category failed: ${nm}`);} ids.push(hit.id);} const res=await wp.post(`/wp/v2/posts/${id}`,{categories:ids}); if(res.status>=200&&res.status<300) return { content:[{type:'text',text:`Categories set: ${ids.join(',')}`}]}; throw new Error(`set_categories_by_names failed: HTTP ${res.status}`); }

    if (name === 'update_title') { const { id, title, if_match } = UpdateTitle.parse(raw); if (if_match){ const cur=await dual.get(`/posts/${id}`); const curTag=String(cur.headers?.etag||'').replace(/^W\//,''); if (curTag && curTag!==if_match) return { isError:true, content:[{type:'text',text:'412 Precondition Failed: Post changed. Re-read required.'}] } as any; } const res=await wp.post(`/wp/v2/posts/${id}`,{title}); if(res.status>=200&&res.status<300){ const fresh=await getMR(id); return { content:[{type:'text',text:`Title updated. New CID: ${fresh.etag}`}]}; } throw new Error(`update_title failed: HTTP ${res.status}`); }

    if (name === 'self_test') { const { id, public:usePublic, exclude } = SelfTest.parse(raw); const excludeKeys=(exclude||'cid').split(',').map(s=>s.trim()).filter(Boolean); const rep:string[]=[]; const prefix=usePublic?'/public':''; const r=await dual.get(`${prefix}/posts/${id}`); rep.push(`MR HTTP ${r.status}`); if(!(r.status>=200&&r.status<300)) return { content:[{type:'text',text:rep.join('\n')}], isError:true } as any; const etag=String(r.headers?.etag||'').replace(/^W\//,'').replace(/^"|"$/g,''); const mr=r.data||{}; const need=['rid','title','status','blocks','word_count','cid']; const miss=need.filter(k=>!(k in mr)); rep.push(miss.length?`FAIL: Missing MR keys ${miss.join(', ')}`:'OK: MR keys present'); rep.push(etag&&mr.cid&&etag===mr.cid?'OK: ETag equals CID':`WARN: ETag (${etag}) != CID (${mr.cid})`); try{ const local=computeCidLocal(mr,excludeKeys); rep.push(local===mr.cid?'OK: Local CID recompute matches':`WARN: Local CID != server CID (${local} vs ${mr.cid})`);}catch(e:any){ rep.push(`WARN: Local CID recompute error: ${e?.message||e}`);} const cdMr=String((r.headers as any)?.['content-digest']||''); if(cdMr){ const rb=await dual.get(`${prefix}/posts/${id}`,{responseType:'arraybuffer',decompress:false,headers:{'Accept-Encoding':'identity'} as any}); if(rb.status>=200&&rb.status<300){ const body=Buffer.from(rb.data); const b64=createHash('sha256').update(body).digest('base64'); const expect=`sha-256=:${b64}:`; const got=cdMr.split(',').map(s=>s.trim()); rep.push(got.includes(expect)?'OK: Content-Digest (MR) matches body bytes':'FAIL: Content-Digest (MR) mismatch'); } } else rep.push('WARN: Content-Digest (MR) header missing'); const r304=await dual.get(`${prefix}/posts/${id}`,{headers:{'If-None-Match':`"${mr.cid||etag}"`}}); rep.push(`MR If-None-Match -> HTTP ${r304.status} (expect 304)`); const md0=await dual.get(`${prefix}/posts/${id}/md`,{responseType:'text'}); rep.push(`MD HTTP ${md0.status}`); const mdE=String(md0.headers?.etag||'').replace(/^W\//,'').replace(/^"|"$/g,''); const cdMd=String((md0.headers as any)?.['content-digest']||''); if(cdMd){ const mb=await dual.get(`${prefix}/posts/${id}/md`,{responseType:'arraybuffer',decompress:false,headers:{'Accept-Encoding':'identity'} as any}); if(mb.status>=200&&mb.status<300){ const body=Buffer.from(mb.data); const b64=createHash('sha256').update(body).digest('base64'); const expect=`sha-256=:${b64}:`; const got=cdMd.split(',').map(s=>s.trim()); rep.push(got.includes(expect)?'OK: Content-Digest (MD) matches body bytes':'FAIL: Content-Digest (MD) mismatch'); } } else rep.push('WARN: Content-Digest (MD) header missing'); const md304=await dual.get(`${prefix}/posts/${id}/md`,{headers:{'If-None-Match':`"${mdE}"`},responseType:'text'}); rep.push(`MD If-None-Match -> HTTP ${md304.status} (expect 304)`); return { content:[{type:'text',text:rep.join('\n')}]}; }

    throw new Error(`Unknown tool: ${name}`);
  } catch (err:any) {
    const message = err?.message || String(err);
    return { content:[{type:'text',text:`Error: ${message}`}], isError:true } as any;
  }
});

const transport = new StdioServerTransport();
await server.connect(transport);

