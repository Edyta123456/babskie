/* diary-client.js - laczy strone z zapleczem na tym serwerze (api.php).
   Udaje male podzbiory interfejsu uzywanego przez strone: from(), storage, auth. */
(function () {
  var API = 'api.php';
  var reloading = false;

  async function call(action, body) {
    var res;
    try {
      res = await fetch(API + '?a=' + action, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'diary' },
        body: JSON.stringify(body || {}),
        credentials: 'same-origin'
      });
    } catch (e) {
      return { data: null, error: { message: 'Brak połączenia z serwerem.' } };
    }
    return finish(res, action);
  }

  async function finish(res, action) {
    var j = null;
    try { j = await res.json(); } catch (e) {}
    if (res.status === 401 && action !== 'login' && action !== 'session' && !reloading) {
      reloading = true;
      location.reload();
    }
    if (!j) return { data: null, error: { message: 'Błąd serwera (' + res.status + ').' } };
    if (j.error === undefined) j.error = null;
    return j;
  }

  function Query(table) {
    this.q = { table: table, op: 'select', cols: '*', filters: [], order: [], single: false, row: null };
  }
  Query.prototype.select = function (cols) { if (this.q.op === 'select') this.q.cols = cols || '*'; return this; };
  Query.prototype.order = function (col, opts) { this.q.order.push({ col: col, asc: !(opts && opts.ascending === false) }); return this; };
  Query.prototype.eq = function (col, val) { this.q.filters.push({ op: 'eq', col: col, val: val }); return this; };
  Query.prototype.in = function (col, vals) { this.q.filters.push({ op: 'in', col: col, val: vals }); return this; };
  Query.prototype.single = function () { this.q.single = true; return this; };
  Query.prototype.insert = function (row) { this.q.op = 'insert'; this.q.row = row; return this; };
  Query.prototype.update = function (row) { this.q.op = 'update'; this.q.row = row; return this; };
  Query.prototype.delete = function () { this.q.op = 'delete'; return this; };
  Query.prototype.then = function (ok, bad) {
    var q = this.q;
    return call(q.op, q).then(ok, bad);
  };

  window.diaryClient = function () {
    return {
      from: function (table) { return new Query(table); },
      storage: {
        from: function () {
          return {
            upload: async function (path, blob) {
              var fd = new FormData();
              fd.append('path', path);
              fd.append('file', blob, 'photo.jpg');
              var res;
              try {
                res = await fetch(API + '?a=upload', {
                  method: 'POST', headers: { 'X-Requested-With': 'diary' }, body: fd, credentials: 'same-origin'
                });
              } catch (e) {
                return { data: null, error: { message: 'Brak połączenia z serwerem.' } };
              }
              return finish(res, 'upload');
            },
            createSignedUrls: function (paths) { return call('sign', { paths: paths }); },
            remove: function (paths) { return call('remove', { paths: paths }); }
          };
        }
      },
      auth: {
        signInWithPassword: function (o) { return call('login', { password: o && o.password }); },
        signOut: function () { return call('logout'); },
        getSession: async function () {
          var r = await call('session');
          var s = r && r.data && r.data.session ? r.data.session : null;
          return { data: { session: s }, error: r ? r.error : null };
        }
      }
    };
  };
})();
