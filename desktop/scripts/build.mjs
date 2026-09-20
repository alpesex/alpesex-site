import { cp, mkdir, rm } from 'node:fs/promises';
const target = new URL('../www/', import.meta.url);
await rm(target, {recursive:true, force:true});
await mkdir(target, {recursive:true});
await cp(new URL('../../application/', target), new URL('application/', target), {recursive:true});
await cp(new URL('../../assets/', target), new URL('assets/', target), {recursive:true});
