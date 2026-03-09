function appendRoutePath(baseUrl, routePath = '/assistant/api/message') {
  const normalizedBase = String(baseUrl || '').trim().replace(/\/+$/, '');
  const normalizedRoute = String(routePath || '/assistant/api/message').startsWith('/')
    ? String(routePath || '/assistant/api/message')
    : `/${String(routePath || '/assistant/api/message')}`;
  return `${normalizedBase}${normalizedRoute}`;
}

function classifyTargetUrl(assistantUrl, ddevPrimaryUrl = '') {
  const parsedAssistantUrl = new URL(assistantUrl);
  const ddevHost = ddevPrimaryUrl ? new URL(ddevPrimaryUrl).hostname : '';
  const assistantHost = parsedAssistantUrl.hostname;
  const targetKind =
    assistantHost.endsWith('.ddev.site') || (ddevHost !== '' && assistantHost === ddevHost)
      ? 'ddev'
      : 'remote';

  return {
    assistantUrl: parsedAssistantUrl.toString(),
    host: assistantHost,
    targetKind,
  };
}

module.exports = {
  appendRoutePath,
  classifyTargetUrl,
};
