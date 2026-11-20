/**
 * Moduli dei plugin per l'editor (docs/2.0/plugin.md, 6): un modulo ES compilato
 * dal plugin, in assets/plugins/<nome>/, che importa "vue" e "@kris/editor" (import
 * map di index.php: lo stesso Vue e lo stesso kit dell'editor) e esporta
 * `default { views: { <nome della schermata>: Componente } }`. Si carica una volta.
 */
const modules = new Map()

export function loadPluginModule(plugin) {
  if (!plugin?.module) return Promise.reject(new Error('Il plugin non ha schermate.'))
  if (plugin.style && !document.querySelector(`link[data-kris-plugin="${plugin.name}"]`)) {
    const link = document.createElement('link')
    link.rel = 'stylesheet'
    link.href = plugin.style
    link.dataset.krisPlugin = plugin.name
    document.head.appendChild(link)
  }
  if (!modules.has(plugin.module)) {
    modules.set(
      plugin.module,
      import(/* @vite-ignore */ plugin.module)
        .then((m) => m.default || m)
        .catch((e) => {
          modules.delete(plugin.module)
          throw e
        })
    )
  }
  return modules.get(plugin.module)
}

/** Il componente di una schermata del plugin, o un errore leggibile. */
export async function pluginView(plugin, view) {
  const mod = await loadPluginModule(plugin)
  const component = mod?.views?.[view]
  if (!component) throw new Error(`Il plugin ${plugin.title} non ha la schermata «${view}».`)
  return component
}
