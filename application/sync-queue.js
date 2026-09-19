(function (root) {
  'use strict';
  // Durable snapshots and their original server revisions are kept together.
  root.CpmpSyncQueue = class {
    constructor(storage, send, onSaved = () => {}) {
      this.storage = storage; this.send = send; this.onSaved = onSaved;
      this.key = 'alpesex.application.drafts'; this.running = new Map();
    }
    entries() { return JSON.parse(this.storage.getItem(this.key) || '{}'); }
    write(entries) { this.storage.setItem(this.key, JSON.stringify(entries)); }
    stage(project, revision) {
      const id = project.meta.portfolioId, entries = this.entries();
      entries[id] = {project: JSON.parse(JSON.stringify(project)), revision: entries[id]?.revision ?? revision};
      this.write(entries);
    }
    remove(id) { const entries = this.entries(); delete entries[id]; this.write(entries); }
    flush(id) {
      if (this.running.has(id)) return this.running.get(id);
      const work = this.run(id).finally(() => this.running.delete(id));
      this.running.set(id, work); return work;
    }
    async run(id) {
      let result;
      for (;;) {
        const entry = this.entries()[id]; if (!entry) return result;
        result = await this.send(entry.project, entry.revision);
        const entries = this.entries(), latest = entries[id];
        if (latest && JSON.stringify(latest.project) === JSON.stringify(entry.project)) delete entries[id];
        else if (latest) latest.revision = result.revision;
        this.write(entries);
        this.onSaved(id, entry.project, result);
      }
    }
  };
})(typeof window === 'undefined' ? globalThis : window);
