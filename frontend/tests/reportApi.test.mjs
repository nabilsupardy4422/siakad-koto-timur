
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const moduleUrl = source =>
  'data:text/javascript;base64,' +
  Buffer.from(source).toString('base64');

test('real reporting service exposes context preview and authenticated export', async () => {
  const dir = new URL('../src/services/', import.meta.url);

  const errorUrl = moduleUrl(
    await readFile(new URL('apiError.js', dir), 'utf8')
  );

  const clientUrl = moduleUrl(
    (await readFile(new URL('apiClient.js', dir), 'utf8'))
      .replace(
        /(['"])\.\/apiError(?:\.js)?\1/,
        JSON.stringify(errorUrl)
      )
      .replaceAll('import.meta.env.', 'import.meta.env?.')
  );

  const { reportApi } = await import(
    moduleUrl(
      (await readFile(new URL('reportApi.js', dir), 'utf8'))
        .replace(
          /(['"])\.\/apiClient(?:\.js)?\1/,
          JSON.stringify(clientUrl)
        )
    )
  );

  const previousFetch = globalThis.fetch;

  const previousStorage = Object.getOwnPropertyDescriptor(
    globalThis,
    'localStorage'
  );

  const calls = [];
  const hash = 'a'.repeat(64);

  Object.defineProperty(globalThis, 'localStorage', {
    configurable: true,
    value: {
      getItem: () => 'test-token',
    },
  });

  globalThis.fetch = async (url, options) => {
    calls.push({
      url: new URL(url),
      options,
    });

    if (calls.length <= 2) {
      return new Response(
        JSON.stringify({
          data: { ok: true },
        }),
        {
          headers: {
            'content-type': 'application/json',
          },
        }
      );
    }

    if (calls.length === 3) {
      return new Response('PDF bytes', {
        headers: {
          'content-type': 'application/pdf',
        },
      });
    }

    return new Response(
      JSON.stringify({
        message: 'Forbidden',
      }),
      {
        status: 403,
        headers: {
          'content-type': 'application/json',
        },
      }
    );
  };

  try {
    // 1. Reporting context API.
    assert.deepEqual(
      await reportApi.context(),
      {
        data: { ok: true },
      }
    );

    // 2. Preview API.
    assert.deepEqual(
      await reportApi.preview('grades', {
        scope: 'teaching',
        academic_year_id: 9,
        page: 1,
        search: '',
        class_id: null,
      }),
      {
        data: { ok: true },
      }
    );

    // 3. Authorized PDF export.
    const file = await reportApi.downloadReport('grades', {
      scope: 'teaching',
      academic_year_id: 9,
      format: 'pdf',
      preview_hash: hash,
    });

    assert.equal(await file.text(), 'PDF bytes');

    // 4. Forbidden master data export.
    await assert.rejects(
      reportApi.downloadReport('teachers', {
        format: 'excel',
      }),
      error => error.status === 403
    );

    // 5. Verify endpoint paths.
    assert.deepEqual(
      calls.map(c => c.url.pathname),
      [
        '/api/reports/context',
        '/api/reports/grades/preview',
        '/api/reports/grades',
        '/api/reports/teachers',
      ]
    );

    // 6. Verify preview query parameters.
    assert.deepEqual(
      Object.fromEntries(calls[1].url.searchParams),
      {
        scope: 'teaching',
        academic_year_id: '9',
        page: '1',
      }
    );

    // 7. Verify export query parameters.
    assert.deepEqual(
      Object.fromEntries(calls[2].url.searchParams),
      {
        scope: 'teaching',
        academic_year_id: '9',
        format: 'pdf',
        preview_hash: hash,
      }
    );

    // 8. Verify authentication and HTTP method.
    for (const { options } of calls) {
      assert.equal(options.method, 'GET');

      assert.equal(
        options.headers.Authorization,
        'Bearer test-token'
      );
    }
  } finally {
    globalThis.fetch = previousFetch;

    if (previousStorage) {
      Object.defineProperty(
        globalThis,
        'localStorage',
        previousStorage
      );
    } else {
      delete globalThis.localStorage;
    }
  }
});
