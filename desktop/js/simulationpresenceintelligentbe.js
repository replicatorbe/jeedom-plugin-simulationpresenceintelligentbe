/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/* ================================================================== OUTILS */

/* Les lampes du groupe ouvert. C'est la source de vérité de l'onglet Lampes :
   l'affichage en découle, et saveEqLogic la recopie dans la configuration. */
var simulationpresenceintelligentbeSelection = []

/* Ce que le sélecteur a trouvé dans l'installation, gardé le temps de la
   fenêtre pour que filtrer et chercher ne relancent pas la découverte. */
var simulationpresenceintelligentbePicker = {
  groups: [],
  checked: {},
  /* Les commandes choisies à la main, par équipement : { eq: {on: id, off: id} }.
     Elles vivent ici et non dans le DOM, qui est reconstruit à chaque frappe
     dans le champ de recherche — un choix posé dans une liste déroulante
     disparaîtrait à la lettre suivante. */
  choice: {},
  search: '',
  filters: { light: true, plug: true, guess: true, unknown: false },
  /* Le sélecteur complet coûte un parcours de toute l'installation : il n'est
     demandé au serveur que si l'utilisateur l'ouvre, et une seule fois. */
  loadedAll: false
}

/* Vrai pendant que printEqLogic repose les valeurs à l'écran. Reposer une
   valeur dans un champ émet « change » exactement comme une saisie : sans ce
   drapeau, ouvrir un groupe suffirait à le déclarer modifié, et l'avertissement
   « quitter sans enregistrer ? » tomberait sans que rien n'ait été touché. */
var simulationpresenceintelligentbeRendering = false

/* Requête AJAX vers le contrôleur du plugin.
   _options : { button: <élément à désactiver pendant l'appel>,
                failure: <fonction recevant le message d'erreur>,
                silent: true pour ne rien afficher } */
function simulationpresenceintelligentbeAjax(_action, _data, _success, _options) {
  var options = _options || {}
  var button = isset(options.button) ? options.button : null
  var released = false
  var release = function () {
    if (button === null || released) { return }
    released = true
    button.removeAttribute('disabled')
    button.classList.remove('disabled')
  }
  if (button !== null) {
    button.setAttribute('disabled', 'disabled')
    button.classList.add('disabled')
    /* Filet de sécurité : jamais de bouton bloqué si la réponse n'arrive pas. */
    setTimeout(release, 60000)
  }

  var payload = Object.assign({ action: _action }, _data || {})
  domUtils.ajax({
    type: 'POST',
    url: 'plugins/simulationpresenceintelligentbe/core/ajax/simulationpresenceintelligentbe.ajax.php',
    data: payload,
    dataType: 'json',
    noDisplayError: true,
    error: function (request, status, error) {
      release()
      if (isset(options.failure)) {
        options.failure('{{Jeedom n\'a pas répondu.}}')
        return
      }
      if (options.silent === true) { return }
      domUtils.handleAjaxError(request, status, error)
    },
    success: function (data) {
      release()
      if (data.state != 'ok') {
        if (isset(options.failure)) {
          options.failure(data.result)
          return
        }
        if (options.silent !== true) {
          jeedomUtils.showAlert({ message: data.result, level: 'danger' })
        }
        return
      }
      _success(data.result)
    }
  })
}

/* Identifiant du groupe ouvert, ou null s'il n'est pas encore enregistré. */
function simulationpresenceintelligentbeCurrentId(_quiet) {
  var input = document.querySelector('.eqLogicAttr[data-l1key="id"]')
  if (input === null || input.value === '') {
    if (_quiet !== true) {
      jeedomUtils.showAlert({ message: '{{Enregistrez d\'abord le groupe.}}', level: 'warning' })
    }
    return null
  }
  return input.value
}

/* Le coeur teste DEUX drapeaux avant d'avertir qu'on quitte une page modifiée :
   n'en poser qu'un laisse passer la perte de données une fois sur deux. */
function simulationpresenceintelligentbeMarkModified() {
  if (simulationpresenceintelligentbeRendering) { return }
  if (typeof jeeFrontEnd !== 'undefined') { jeeFrontEnd.modifyWithoutSave = true }
  window.modifyWithoutSave = true
}

/* Une ligne de texte posée sans balisage : les noms affichés viennent des
   équipements de l'utilisateur, et rien ne garantit ce qu'ils contiennent. */
function simulationpresenceintelligentbeText(_tag, _className, _text) {
  var element = document.createElement(_tag)
  if (_className !== '') { element.className = _className }
  element.textContent = String(isset(_text) ? _text : '')
  return element
}

/* ========================================================= LAMPES DU GROUPE */

/* La pastille d'état d'une lampe : allumée, éteinte, ou inconnue. C'est elle
   qui permet de reconnaître une lampe du premier coup d'oeil après avoir appuyé
   sur le bouton d'essai. */
function simulationpresenceintelligentbeStateDot(_value) {
  var icon = document.createElement('i')
  if (_value === null || _value === undefined || _value === '') {
    icon.className = 'fas fa-circle'
    icon.style.opacity = '0.25'
    icon.title = '{{État inconnu}}'
  } else if (_value == 1) {
    icon.className = 'fas fa-circle'
    icon.style.color = '#f0ad4e'
    icon.title = '{{Allumée}}'
  } else {
    icon.className = 'far fa-circle'
    icon.style.opacity = '0.5'
    icon.title = '{{Éteinte}}'
  }
  icon.style.marginRight = '6px'
  return icon
}

/* Dessine les lampes retenues. Un groupe vide le dit : une liste vide sans un
   mot ressemble à un chargement qui n'a pas abouti. */

function simulationpresenceintelligentbeClosePicker() {
  if (typeof jeeDialog === 'undefined') { return }
  var dialog = jeeDialog.get('#jee_modal')
  if (dialog !== null && typeof dialog.close === 'function') { dialog.close() }
}

function simulationpresenceintelligentbeOpenPicker() {
  if (typeof jeeDialog === 'undefined') {
    jeedomUtils.showAlert({ message: '{{Cette version de Jeedom ne sait pas ouvrir le sélecteur.}}', level: 'danger' })
    return
  }
  /* Les lampes déjà retenues arrivent cochées : le sélecteur sert aussi bien à
     ajouter qu'à retirer, et rouvrir sur une liste vierge donnerait l'impression
     d'avoir tout perdu. */
  simulationpresenceintelligentbePicker.checked = {}
  simulationpresenceintelligentbePicker.choice = {}
  for (var i = 0; i < simulationpresenceintelligentbeSelection.length; i++) {
    var known = simulationpresenceintelligentbeSelection[i]
    simulationpresenceintelligentbePicker.checked[known.eq] = true
    /* Ce qui a déjà été retenu pour cette lampe, y compris un choix fait à la
       main la fois précédente : le sélecteur doit rouvrir sur l'existant. */
    simulationpresenceintelligentbePicker.choice[known.eq] = { on: known.on, off: known.off, toggle: known.toggle, state: known.state }
  }
  simulationpresenceintelligentbePicker.search = ''
  simulationpresenceintelligentbePicker.loadedAll = false
  /* Les filtres aussi : le balisage de la fenêtre revient toujours à « Lampes,
     Prises, Autres » actifs, et garder l'objet dans son dernier état faisait
     que le bouton « Tous les équipements » demandait deux clics à la deuxième
     ouverture — le premier ne faisait rien de visible. */
  simulationpresenceintelligentbePicker.filters = { light: true, plug: true, guess: true, unknown: false }

  jeeDialog.dialog({
    id: 'jee_modal',
    title: '{{Choisir les lampes de ce groupe}}',
    contentUrl: 'index.php?v=d&plugin=simulationpresenceintelligentbe&modal=lamp.picker',
    callback: function () { simulationpresenceintelligentbePickerStart() }
  })
}

/* La fenêtre existe : on branche ses écouteurs et on lance la découverte.
   Les écouteurs sont posés ici, sur la racine de la fenêtre, et disparaissent
   avec elle — sur le corps du document, ils s'empileraient à chaque ouverture. */
function simulationpresenceintelligentbePickerStart() {
  var root = document.getElementById('div_simulationpresenceintelligentbePicker')
  if (root === null) { return }

  root.addEventListener('input', function (event) {
    if (event.target.closest('#in_simulationpresenceintelligentbeSearch')) {
      simulationpresenceintelligentbePicker.search = event.target.value.trim().toLowerCase()
      simulationpresenceintelligentbePickerRender()
    }
  })

  root.addEventListener('click', function (event) {
    var target = null

    if (target = event.target.closest('.spiFilter')) {
      var filter = target.getAttribute('data-filter')
      simulationpresenceintelligentbePicker.filters[filter] = !simulationpresenceintelligentbePicker.filters[filter]
      target.classList.toggle('active', simulationpresenceintelligentbePicker.filters[filter])
      /* Les équipements inconnus ne sont pas dans la première réponse : les
         montrer demande de redemander la liste, complète cette fois. */
      if (filter === 'unknown' && simulationpresenceintelligentbePicker.filters.unknown && !simulationpresenceintelligentbePicker.loadedAll) {
        simulationpresenceintelligentbePickerLoad(true, target)
        return
      }
      simulationpresenceintelligentbePickerRender()
      return
    }
    if (target = event.target.closest('.spiPickTune')) {
      var block = target.closest('.spiPickRow').querySelector('.spiPickCmds')
      block.style.display = (block.style.display === 'none') ? '' : 'none'
      return
    }
    if (target = event.target.closest('.spiPickRoom')) {
      /* Cocher une pièce entière est le geste le plus fréquent : « toutes les
         lampes du salon » est une intention, pas six décisions. */
      var room = target.getAttribute('data-room')
      var checkboxes = document.querySelectorAll('#div_simulationpresenceintelligentbePickerList .spiPickLamp[data-room="' + CSS.escape(room) + '"]')
      var check = target.getAttribute('data-check') !== '0'
      for (var i = 0; i < checkboxes.length; i++) {
        checkboxes[i].checked = check
        simulationpresenceintelligentbePicker.checked[checkboxes[i].getAttribute('data-eq')] = check
      }
      target.setAttribute('data-check', check ? '0' : '1')
      /* Le libellé suit l'action : laisser « Tout cocher » sur un bouton qui
         décoche est le meilleur moyen de vider une pièce entière en croyant la
         remplir. */
      target.textContent = check ? '{{Tout décocher}}' : '{{Tout cocher}}'
      simulationpresenceintelligentbePickerCount()
      return
    }
    if ((target = event.target.closest('.spiPickOn')) || (target = event.target.closest('.spiPickOff'))) {
      simulationpresenceintelligentbePickerSwitch(target, target.classList.contains('spiPickOn') ? 'on' : 'off')
      return
    }
    if (event.target.closest('#bt_simulationpresenceintelligentbePickerValidate')) {
      simulationpresenceintelligentbePickerValidate()
      return
    }
    if (event.target.closest('#bt_simulationpresenceintelligentbePickerCancel')) {
      simulationpresenceintelligentbeClosePicker()
      return
    }
  })

  root.addEventListener('change', function (event) {
    var checkbox = event.target.closest('.spiPickLamp')
    if (checkbox !== null) {
      simulationpresenceintelligentbePicker.checked[checkbox.getAttribute('data-eq')] = checkbox.checked
      simulationpresenceintelligentbePickerCount()
      return
    }
    var select = event.target.closest('.spiPickCmd')
    if (select === null) { return }
    var eq = select.getAttribute('data-eq')
    if (!isset(simulationpresenceintelligentbePicker.choice[eq])) { simulationpresenceintelligentbePicker.choice[eq] = {} }
    simulationpresenceintelligentbePicker.choice[eq][select.getAttribute('data-role')] = (select.value === '') ? null : parseInt(select.value, 10)
    /* Désigner une commande, c'est vouloir la lampe : cocher soi-même ensuite
       serait un geste de plus pour rien. */
    var row = select.closest('.spiPickRow').querySelector('.spiPickLamp')
    if (row !== null && !row.checked && select.value !== '') {
      row.checked = true
      simulationpresenceintelligentbePicker.checked[eq] = true
      simulationpresenceintelligentbePickerCount()
    }
  })

  simulationpresenceintelligentbePickerLoad(false, null)
}

/* Demande la liste au serveur. _all ajoute les équipements dont le plugin ne
   sait rien dire. */
function simulationpresenceintelligentbePickerLoad(_all, _button) {
  var list = document.getElementById('div_simulationpresenceintelligentbePickerList')
  if (list !== null) {
    list.innerHTML = '<div class="text-center" style="padding:30px;"><i class="fas fa-spinner fa-spin fa-2x"></i></div>'
  }
  simulationpresenceintelligentbeAjax('lamps', _all ? { all: 1 } : {}, function (result) {
    simulationpresenceintelligentbePicker.groups = result.groups
    if (_all) { simulationpresenceintelligentbePicker.loadedAll = true }
    simulationpresenceintelligentbePickerRender()
  }, {
    button: _button,
    failure: function (message) {
      var target = document.getElementById('div_simulationpresenceintelligentbePickerList')
      if (target === null) { return }
      target.innerHTML = ''
      target.appendChild(simulationpresenceintelligentbeText('div', 'alert alert-danger', message))
    }
  })
}

/* Allume ou éteint une lampe depuis le sélecteur, pour la reconnaître.
   Seul l'équipement est envoyé : c'est le serveur qui décide quelle commande
   l'allume. */
function simulationpresenceintelligentbePickerSwitch(_button, _order) {
  var eq = _button.getAttribute('data-eq')
  /* La commande désignée à la main l'emporte : pour un équipement inconnu, le
     serveur n'a rien d'autre pour savoir quoi jouer. */
  var choice = isset(simulationpresenceintelligentbePicker.choice[eq]) ? simulationpresenceintelligentbePicker.choice[eq] : {}
  var cmd = (_order === 'on') ? choice.on : choice.off
  simulationpresenceintelligentbeAjax('switchLamp', { eq: eq, order: _order, cmd: isset(cmd) && cmd !== null ? cmd : '' }, function () {
    var dot = document.querySelector('#div_simulationpresenceintelligentbePickerList .spiPickDot[data-eq="' + eq + '"]')
    if (dot !== null) {
      dot.replaceWith(simulationpresenceintelligentbePickerDot(eq, (_order === 'on') ? 1 : 0))
    }
  }, { button: _button })
}

function simulationpresenceintelligentbePickerDot(_eq, _value) {
  var dot = simulationpresenceintelligentbeStateDot(_value)
  dot.classList.add('spiPickDot')
  dot.setAttribute('data-eq', _eq)
  return dot
}

/* Dessine la liste, filtres et recherche appliqués. */
function simulationpresenceintelligentbePickerRender() {
  var list = document.getElementById('div_simulationpresenceintelligentbePickerList')
  if (list === null) { return }
  list.innerHTML = ''

  var counts = { light: 0, plug: 0, guess: 0, unknown: 0 }
  var shown = 0

  for (var g = 0; g < simulationpresenceintelligentbePicker.groups.length; g++) {
    var group = simulationpresenceintelligentbePicker.groups[g]
    var visible = []

    for (var l = 0; l < group.lamps.length; l++) {
      var lamp = group.lamps[l]
      counts[lamp.confidence]++
      if (simulationpresenceintelligentbePicker.filters[lamp.confidence] !== true) { continue }
      if (simulationpresenceintelligentbePicker.search !== '') {
        var haystack = (lamp.name + ' ' + group.object + ' ' + lamp.plugin).toLowerCase()
        if (haystack.indexOf(simulationpresenceintelligentbePicker.search) === -1) { continue }
      }
      visible.push(lamp)
    }
    if (visible.length === 0) { continue }
    shown += visible.length

    var header = document.createElement('div')
    header.style.cssText = 'margin:10px 0 4px 0;padding-bottom:3px;border-bottom:1px solid rgba(128,128,128,0.3);'
    header.appendChild(simulationpresenceintelligentbeText('b', '', group.object))
    var all = document.createElement('a')
    all.className = 'btn btn-xs btn-default pull-right spiPickRoom'
    all.setAttribute('data-room', group.object)
    all.setAttribute('data-check', '1')
    all.textContent = '{{Tout cocher}}'
    header.appendChild(all)
    list.appendChild(header)

    for (var v = 0; v < visible.length; v++) {
      list.appendChild(simulationpresenceintelligentbePickerRow(visible[v], group.object))
    }
  }

  for (var key in counts) {
    var badge = document.querySelector('#div_simulationpresenceintelligentbePicker .badge[data-count="' + key + '"]')
    if (badge !== null) { badge.textContent = counts[key] }
  }

  if (shown === 0) {
    var empty = document.createElement('div')
    empty.className = 'alert alert-warning'
    empty.textContent = (simulationpresenceintelligentbePicker.search !== '')
      ? '{{Aucune lampe ne correspond à cette recherche.}}'
      : '{{Aucune lampe trouvée avec ces filtres. Essayez « Autres » : certains protocoles ne renseignent pas le type des commandes.}}'
    list.appendChild(empty)
  }
  simulationpresenceintelligentbePickerCount()
}

function simulationpresenceintelligentbePickerRow(_lamp, _room) {
  var eq = parseInt(_lamp.eq, 10)
  var isUnknown = (_lamp.confidence === 'unknown')

  var row = document.createElement('div')
  row.className = 'spiPickRow'
  row.setAttribute('data-eq', eq)
  row.style.cssText = 'padding:3px 0;'

  var line = document.createElement('div')
  line.style.cssText = 'display:flex;align-items:center;'

  var label = document.createElement('label')
  label.style.cssText = 'flex:1;margin:0;font-weight:normal;cursor:pointer;'

  var checkbox = document.createElement('input')
  checkbox.type = 'checkbox'
  checkbox.className = 'spiPickLamp'
  checkbox.setAttribute('data-eq', eq)
  checkbox.setAttribute('data-room', _room)
  checkbox.checked = (simulationpresenceintelligentbePicker.checked[eq] === true)
  checkbox.style.marginRight = '8px'
  label.appendChild(checkbox)

  label.appendChild(simulationpresenceintelligentbePickerDot(eq, _lamp.value))
  label.appendChild(simulationpresenceintelligentbeText('span', '', _lamp.name))

  /* D'où vient la lampe et à quel point on en est sûr : sans cela, deux
     équipements homonymes venus de deux plugins seraient indiscernables. */
  var origin = simulationpresenceintelligentbeText('span', 'label label-default', _lamp.plugin)
  origin.style.marginLeft = '8px'
  origin.style.opacity = '0.7'
  label.appendChild(origin)

  if (_lamp.confidence !== 'light') {
    var kind = simulationpresenceintelligentbeText('span', isUnknown ? 'label label-warning' : 'label label-info',
      (_lamp.confidence === 'plug') ? '{{prise}}'
        : (isUnknown ? '{{à désigner}}' : '{{reconnue au nom}}'))
    kind.style.marginLeft = '4px'
    label.appendChild(kind)
  }
  line.appendChild(label)

  var buttons = document.createElement('span')
  buttons.style.whiteSpace = 'nowrap'
  buttons.innerHTML = '<a class="btn btn-xs btn-default spiPickTune" title="{{Voir et changer les commandes retenues}}"><i class="fas fa-sliders-h"></i></a> '
                    + '<a class="btn btn-xs btn-warning spiPickOn" data-eq="' + eq + '" title="{{Allumer pour la reconnaître}}"><i class="fas fa-lightbulb"></i></a> '
                    + '<a class="btn btn-xs btn-default spiPickOff" data-eq="' + eq + '" title="{{Éteindre}}"><i class="far fa-lightbulb"></i></a>'
  line.appendChild(buttons)
  row.appendChild(line)

  /*
   * Les deux commandes retenues, modifiables.
   *
   * Dépliées d'office pour un équipement inconnu : c'est la seule chose à y
   * faire, et une ligne cochée sans commande ne commanderait rien. Repliées
   * pour les autres, dont le plugin s'est déjà chargé.
   */
  var cmds = document.createElement('div')
  cmds.className = 'spiPickCmds'
  cmds.style.cssText = 'padding:4px 0 8px 26px;display:' + (isUnknown ? '' : 'none') + ';'
  cmds.appendChild(simulationpresenceintelligentbePickerCmdSelect(_lamp, 'on', '{{Allumer avec}}'))
  cmds.appendChild(simulationpresenceintelligentbePickerCmdSelect(_lamp, 'off', '{{Éteindre avec}}'))
  row.appendChild(cmds)

  return row
}

/* Une liste déroulante des commandes d'action de l'équipement, positionnée sur
   celle que le plugin a retenue — ou sur celle que l'utilisateur a désignée. */
function simulationpresenceintelligentbePickerCmdSelect(_lamp, _role, _label) {
  var eq = parseInt(_lamp.eq, 10)
  var chosen = isset(simulationpresenceintelligentbePicker.choice[eq]) && isset(simulationpresenceintelligentbePicker.choice[eq][_role])
    ? simulationpresenceintelligentbePicker.choice[eq][_role]
    : _lamp[_role]

  var group = document.createElement('div')
  group.style.cssText = 'display:inline-block;margin-right:12px;'
  var caption = simulationpresenceintelligentbeText('span', '', _label + ' ')
  caption.style.opacity = '0.75'
  group.appendChild(caption)

  var select = document.createElement('select')
  select.className = 'spiPickCmd'
  select.setAttribute('data-eq', eq)
  select.setAttribute('data-role', _role)
  select.style.cssText = 'max-width:220px;display:inline-block;'
  select.classList.add('form-control', 'input-sm')

  var none = document.createElement('option')
  none.value = ''
  none.textContent = '{{aucune}}'
  select.appendChild(none)

  var cmds = isset(_lamp.cmds) ? _lamp.cmds : []
  for (var i = 0; i < cmds.length; i++) {
    var option = document.createElement('option')
    option.value = cmds[i].id
    option.textContent = cmds[i].name
    if (chosen !== null && chosen !== undefined && String(chosen) === String(cmds[i].id)) {
      option.selected = true
    }
    select.appendChild(option)
  }
  /* Une bascule tient lieu des deux ordres quand l'équipement ne sait faire que
     ça : elle est proposée dans les deux listes, sous son vrai nom. */
  group.appendChild(select)
  return group
}

function simulationpresenceintelligentbePickerCount() {
  var count = 0
  for (var eq in simulationpresenceintelligentbePicker.checked) {
    if (simulationpresenceintelligentbePicker.checked[eq] === true) { count++ }
  }
  var label = document.getElementById('span_simulationpresenceintelligentbePickerCount')
  if (label !== null) { label.textContent = count + ' {{sélectionnée(s)}}' }
}

/* Reporte la sélection dans le groupe. Les commandes on/off/état sont celles
   que le détecteur a retenues, l'utilisateur n'a jamais à les voir. */
function simulationpresenceintelligentbePickerValidate() {
  /* Ce qui est à l'écran d'abord : la colonne « Active » du tableau n'est
     relue qu'à l'enregistrement, et reconstruire la sélection sans elle
     recochait toutes les lampes qu'on venait de mettre en sommeil. */
  simulationpresenceintelligentbeReadLamps()

  var connues = {}
  for (var k = 0; k < simulationpresenceintelligentbeSelection.length; k++) {
    connues[simulationpresenceintelligentbeSelection[k].eq] = simulationpresenceintelligentbeSelection[k]
  }

  var selection = []
  var sansCommande = []
  var vues = {}

  for (var g = 0; g < simulationpresenceintelligentbePicker.groups.length; g++) {
    var group = simulationpresenceintelligentbePicker.groups[g]
    for (var l = 0; l < group.lamps.length; l++) {
      var lamp = group.lamps[l]
      vues[lamp.eq] = true
      if (simulationpresenceintelligentbePicker.checked[lamp.eq] !== true) { continue }

      /* Ce que l'utilisateur a désigné l'emporte sur ce que le plugin a retenu,
         y compris « aucune » : hasOwnProperty et non une valeur par défaut, sans
         quoi un choix remis à « aucune » retomberait sur la détection. */
      var choice = isset(simulationpresenceintelligentbePicker.choice[lamp.eq]) ? simulationpresenceintelligentbePicker.choice[lamp.eq] : {}
      var on = choice.hasOwnProperty('on') ? choice.on : lamp.on
      var off = choice.hasOwnProperty('off') ? choice.off : lamp.off
      var toggle = isset(lamp.toggle) ? lamp.toggle : null

      /* Il faut de quoi allumer ET de quoi éteindre. Une lampe qu'on ne peut
         pas éteindre brûlerait du soir au matin : c'est exactement ce qu'une
         simulation de présence doit éviter, et l'écarter en silence serait pire
         que de le dire. */
      var peutAllumer = (isset(on) && on !== null) || (toggle !== null)
      var peutEteindre = (isset(off) && off !== null) || (toggle !== null)
      if (!peutAllumer || !peutEteindre) {
        sansCommande.push(lamp.name)
        continue
      }

      var ancienne = isset(connues[lamp.eq]) ? connues[lamp.eq] : null
      selection.push({
        eq: lamp.eq, name: lamp.name, object: group.object,
        on: isset(on) ? on : null, off: isset(off) ? off : null,
        toggle: toggle, state: lamp.state,
        value: lamp.value, missing: 0,
        /* Une lampe déjà présente garde son état « Active » : valider le
           sélecteur pour ajouter une lampe du garage ne doit pas réveiller
           celle de la chambre qu'on avait mise en sommeil. */
        enabled: (ancienne !== null && ancienne.enabled === 0) ? 0 : 1
      })
    }
  }

  /* Les lampes cochées que la découverte n'a pas rendues sont conservées telles
     quelles : un équipement désactivé depuis, ou choisi via « Tous les
     équipements » alors que la liste rouverte ne les contient plus,
     disparaissait du groupe sans un mot — il suffisait d'ouvrir le sélecteur et
     de valider sans rien toucher. */
  for (var eq in connues) {
    if (vues[eq] === true) { continue }
    if (simulationpresenceintelligentbePicker.checked[eq] === false) { continue }
    selection.push(connues[eq])
  }

  simulationpresenceintelligentbeSelection = selection
  simulationpresenceintelligentbeRenderLamps()
  simulationpresenceintelligentbeMarkModified()
  simulationpresenceintelligentbeClosePicker()
  simulationpresenceintelligentbeRefreshLearning(null)

  if (sansCommande.length > 0) {
    /* showAlert pose son message en HTML : les noms viennent des équipements de
       l'utilisateur, et rien ne garantit ce qu'ils contiennent. */
    var noms = []
    for (var n = 0; n < sansCommande.length; n++) { noms.push(String(sansCommande[n]).HTMLFormat()) }
    jeedomUtils.showAlert({
      message: '{{Écartées, faute de quoi les allumer et les éteindre :}} ' + noms.join(', '),
      level: 'warning', timeOut: 8000
    })
  }
  jeedomUtils.showAlert({ message: selection.length + ' {{lampe(s) dans ce groupe. Pensez à sauvegarder.}}', level: 'success' })
}

/* =========================================================== PROGRAMMATION */


function addCmdToTable(_cmd) {
  if (!isset(_cmd)) {
    var _cmd = { configuration: {} }
  }
  if (!isset(_cmd.configuration)) {
    _cmd.configuration = {}
  }

  var tr = '<td>'
  tr += '<span class="cmdAttr" data-l1key="id" style="display:none;"></span>'
  tr += '<div class="input-group">'
  tr += '<input class="cmdAttr form-control input-sm roundedLeft" data-l1key="name" placeholder="{{Nom}}">'
  tr += '<span class="input-group-btn">'
  tr += '<a class="cmdAction btn btn-sm btn-default" data-l1key="chooseIcon" title="{{Choisir une icône}}"><i class="fas fa-icons"></i></a>'
  tr += '</span>'
  tr += '<span class="cmdAttr input-group-addon roundedRight" data-l1key="display" data-l2key="icon" style="font-size:19px;padding:0 5px 0 0!important;"></span>'
  tr += '</div>'
  tr += '</td>'
  tr += '<td>'
  tr += '<span class="type" type="' + init(_cmd.type) + '">' + jeedom.cmd.availableType() + '</span>'
  tr += '<span class="subType" subType="' + init(_cmd.subType) + '"></span>'
  tr += '</td>'
  tr += '<td>'
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked>{{Afficher}}</label>'
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized" checked>{{Historiser}}</label>'
  tr += '<span class="cmdAttr" data-l1key="htmlstate" style="display:inline-block;margin-left:5px;"></span>'
  tr += '</td>'
  tr += '<td>'
  if (is_numeric(_cmd.id)) {
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> '
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a> '
  }
  tr += '<a class="btn btn-danger btn-xs cmdAction pull-right" data-action="remove"><i class="fas fa-minus-circle"></i></a>'
  tr += '</td>'

  /* Une ligne créée en DOM : insertAdjacentHTML sur la table génère un <tbody>
     par insertion et toutes les commandes se retrouveraient dans la même ligne. */
  var newRow = document.createElement('tr')
  newRow.innerHTML = tr
  newRow.classList.add('cmd')
  newRow.setAttribute('data-cmd_id', init(_cmd.id))
  newRow.setAttribute('title', '{{Identifiant interne}} : ' + init(_cmd.logicalId))
  document.getElementById('table_cmd').querySelector('tbody').appendChild(newRow)
  newRow.setJeeValues(_cmd, '.cmdAttr')
  jeedom.cmd.changeType(newRow, init(_cmd.subType))
}

/* Recopie la colonne « Active » du tableau dans la liste. Appelée avant tout ce
   qui redessine le tableau ou le reconstruit, sans quoi une lampe mise en
   sommeil se réveille au premier geste suivant. */
function simulationpresenceintelligentbeReadLamps() {
  var rows = document.querySelectorAll('#table_spiLamps tbody tr[data-index]')
  for (var i = 0; i < rows.length; i++) {
    var index = parseInt(rows[i].getAttribute('data-index'), 10)
    var check = rows[i].querySelector('.spiLampEnabled')
    if (isset(simulationpresenceintelligentbeSelection[index]) && check !== null) {
      simulationpresenceintelligentbeSelection[index].enabled = check.checked ? 1 : 0
    }
  }
}

/* Les réglages à l'écran ne sont pas ceux sur lesquels le serveur va travailler.
   Tous les boutons de cette page relisent la configuration en base : prévenir
   vaut mieux que laisser croire qu'« Historiser les lampes » a porté sur les six
   lampes qu'on vient de cocher. */
function simulationpresenceintelligentbeWarnUnsaved() {
  var modifie = (typeof jeeFrontEnd !== 'undefined' && jeeFrontEnd.modifyWithoutSave === true) || window.modifyWithoutSave === true
  if (!modifie) { return }
  jeedomUtils.showAlert({
    message: '{{Ce calcul porte sur la version enregistrée du groupe : vos modifications en cours ne sont pas prises en compte.}}',
    level: 'warning', timeOut: 6000
  })
}

/* =============================================================== CONDITIONS */

/* Les lignes de conditions du groupe ouvert. Comme les lampes, elles vivent
   ici et non dans le DOM : le tableau est redessiné à chaque évaluation, et un
   champ à moitié rempli disparaîtrait à ce moment-là. */
var simulationpresenceintelligentbeConditions = []

function simulationpresenceintelligentbeRenderConditions() {
  var body = document.querySelector('#table_spiConditions tbody')
  if (body === null) { return }
  body.innerHTML = ''

  if (simulationpresenceintelligentbeConditions.length === 0) {
    var row = document.createElement('tr')
    var cell = document.createElement('td')
    cell.colSpan = 5
    cell.appendChild(simulationpresenceintelligentbeText('span', 'help-block',
      '{{Aucune condition : la simulation ne partira que sur l\'ordre « Démarrer ».}}'))
    row.appendChild(cell)
    body.appendChild(row)
    return
  }

  for (var i = 0; i < simulationpresenceintelligentbeConditions.length; i++) {
    body.appendChild(simulationpresenceintelligentbeConditionRow(simulationpresenceintelligentbeConditions[i], i))
  }
}

function simulationpresenceintelligentbeConditionRow(_condition, _index) {
  /* Une ligne créée en DOM et non par insertAdjacentHTML : sur une table,
     l'insertion de balisage crée un <tbody> par appel et toutes les lignes se
     retrouveraient empilées dans le premier. */
  var row = document.createElement('tr')
  row.setAttribute('data-index', _index)

  var cell = document.createElement('td')
  var group = document.createElement('div')
  group.className = 'input-group'
  var input = document.createElement('input')
  input.className = 'form-control input-sm spiConditionName'
  input.setAttribute('readonly', 'readonly')
  /* Le nom vient d'un équipement de l'utilisateur : posé en valeur, jamais en
     balisage. */
  input.value = isset(_condition.name) && _condition.name !== '' ? _condition.name : '{{Choisissez une commande}}'
  group.appendChild(input)
  var buttons = document.createElement('span')
  buttons.className = 'input-group-btn'
  var pick = document.createElement('a')
  pick.className = 'btn btn-default btn-sm spiPickCondition'
  pick.innerHTML = '<i class="fas fa-list-alt"></i>'
  pick.title = '{{Choisir une commande d\'information}}'
  buttons.appendChild(pick)
  group.appendChild(buttons)
  cell.appendChild(group)
  row.appendChild(cell)

  cell = document.createElement('td')
  var select = document.createElement('select')
  select.className = 'form-control input-sm spiConditionOperator'
  var operators = ['==', '!=', '>', '>=', '<', '<=']
  for (var o = 0; o < operators.length; o++) {
    var option = document.createElement('option')
    option.value = operators[o]
    option.textContent = operators[o]
    if (_condition.operator === operators[o]) { option.selected = true }
    select.appendChild(option)
  }
  cell.appendChild(select)
  row.appendChild(cell)

  cell = document.createElement('td')
  var value = document.createElement('input')
  value.className = 'form-control input-sm spiConditionValue'
  value.value = isset(_condition.value) ? _condition.value : '1'
  value.placeholder = '1'
  cell.appendChild(value)
  row.appendChild(cell)

  cell = document.createElement('td')
  cell.className = 'spiConditionLive'
  if (isset(_condition.live)) {
    var badge = simulationpresenceintelligentbeText('span', 'label label-' + (_condition.met === 1 ? 'success' : 'default'),
      _condition.live === '' ? '{{illisible}}' : _condition.live)
    cell.appendChild(badge)
  } else {
    cell.appendChild(simulationpresenceintelligentbeText('span', 'help-block', '—'))
  }
  row.appendChild(cell)

  cell = document.createElement('td')
  var remove = document.createElement('a')
  remove.className = 'btn btn-danger btn-xs spiRemoveCondition'
  remove.innerHTML = '<i class="fas fa-minus-circle"></i>'
  cell.appendChild(remove)
  row.appendChild(cell)

  return row
}

/* Recopie ce qui est à l'écran dans la liste : appelé avant toute opération
   qui redessine le tableau, sans quoi un opérateur changé serait perdu. */
function simulationpresenceintelligentbeReadConditions() {
  var rows = document.querySelectorAll('#table_spiConditions tbody tr[data-index]')
  for (var i = 0; i < rows.length; i++) {
    var index = parseInt(rows[i].getAttribute('data-index'), 10)
    if (!isset(simulationpresenceintelligentbeConditions[index])) { continue }
    var operator = rows[i].querySelector('.spiConditionOperator')
    var value = rows[i].querySelector('.spiConditionValue')
    if (operator !== null) { simulationpresenceintelligentbeConditions[index].operator = operator.value }
    if (value !== null) { simulationpresenceintelligentbeConditions[index].value = value.value }
  }
}

/* Demande au serveur ce que valent les conditions maintenant. C'est la seule
   réponse à « pourquoi la simulation ne démarre-t-elle pas ? ». */
function simulationpresenceintelligentbeRefreshStatus(_button) {
  var id = simulationpresenceintelligentbeCurrentId(true)
  if (id === null) { return }

  /* Ce qui est à l'écran est repris avant le redessin : sans cette ligne, un
     clic sur « Démarrer » depuis un autre onglet effaçait la valeur attendue
     qu'on venait de saisir, et l'ancienne repartait à l'enregistrement. */
  simulationpresenceintelligentbeReadConditions()

  simulationpresenceintelligentbeAjax('conditions', { id: id }, function (result) {
    /* Appariement par commande et non par rang : le serveur répond sur les
       conditions enregistrées, et le tableau a pu perdre une ligne depuis. Par
       rang, la ligne restante héritait du nom et de l'état de la ligne
       supprimée — une condition dont le libellé désigne une autre commande. */
    var parCmd = {}
    for (var i = 0; i < result.rows.length; i++) {
      parCmd[result.rows[i].cmd] = result.rows[i]
    }
    for (var c = 0; c < simulationpresenceintelligentbeConditions.length; c++) {
      var condition = simulationpresenceintelligentbeConditions[c]
      var vue = isset(parCmd[condition.cmd]) ? parCmd[condition.cmd] : null
      if (vue === null) {
        /* Pas encore enregistrée : on n'a rien à dire de son état. */
        delete condition.live
        delete condition.met
        continue
      }
      condition.name = vue.name
      condition.live = vue.value
      condition.met = vue.met
    }
    simulationpresenceintelligentbeRenderConditions()

    var box = document.getElementById('div_spiStatus')
    if (box === null) { return }
    var text = ''
    var level = 'info'
    if (result.active === 1) {
      text = '{{Simulation en cours.}}'
      level = 'success'
    } else if (result.manual === 'off') {
      text = '{{Arrêtée à la main. La condition ne la relancera pas tant que « Rendre la main à la condition » n\'aura pas été joué.}}'
      level = 'warning'
    } else if (result.met === null) {
      text = '{{En attente d\'un ordre : aucune condition n\'est active.}}'
    } else if (result.met === 1) {
      text = '{{La condition est remplie ; la simulation va démarrer.}}'
      level = 'success'
    } else {
      text = '{{La condition n\'est pas remplie.}}'
    }
    if (result.manual === 'on' && result.active === 1) {
      text += ' {{(démarrage manuel)}}'
    }
    box.className = 'alert alert-' + level
    box.textContent = text
  }, { button: _button, silent: true })
}

/* ==================================================================== LAMPES */

function simulationpresenceintelligentbeRenderLamps() {
  var body = document.querySelector('#table_spiLamps tbody')
  if (body === null) { return }
  body.innerHTML = ''

  var label = document.getElementById('span_spiLampCount')
  if (label !== null) {
    label.textContent = simulationpresenceintelligentbeSelection.length + ' {{lampe(s)}}'
  }

  if (simulationpresenceintelligentbeSelection.length === 0) {
    var empty = document.createElement('tr')
    var cell = document.createElement('td')
    cell.colSpan = 5
    cell.appendChild(simulationpresenceintelligentbeText('span', 'help-block',
      '{{Aucune lampe. Le bouton « Choisir les lampes » va les chercher dans votre installation.}}'))
    empty.appendChild(cell)
    body.appendChild(empty)
    return
  }

  for (var i = 0; i < simulationpresenceintelligentbeSelection.length; i++) {
    var lamp = simulationpresenceintelligentbeSelection[i]
    var row = document.createElement('tr')
    row.setAttribute('data-index', i)

    var cell = document.createElement('td')
    var check = document.createElement('input')
    check.type = 'checkbox'
    check.className = 'spiLampEnabled'
    check.checked = (lamp.enabled !== 0)
    check.title = '{{Décocher laisse la lampe tranquille sans la retirer du groupe}}'
    cell.appendChild(check)
    row.appendChild(cell)

    cell = document.createElement('td')
    var dot = simulationpresenceintelligentbeStateDot(isset(lamp.value) ? lamp.value : null)
    dot.classList.add('spiStateDot')
    dot.setAttribute('data-eq', lamp.eq)
    cell.appendChild(dot)
    cell.appendChild(simulationpresenceintelligentbeText('span', '', ' ' + lamp.name))
    row.appendChild(cell)

    cell = document.createElement('td')
    cell.appendChild(simulationpresenceintelligentbeText('span', '', isset(lamp.object) ? lamp.object : ''))
    row.appendChild(cell)

    cell = document.createElement('td')
    cell.className = 'spiLampHistory'
    cell.setAttribute('data-eq', lamp.eq)
    cell.appendChild(simulationpresenceintelligentbeText('span', 'help-block', '—'))
    row.appendChild(cell)

    cell = document.createElement('td')
    var remove = document.createElement('a')
    remove.className = 'btn btn-danger btn-xs spiRemoveLamp'
    remove.innerHTML = '<i class="fas fa-minus-circle"></i>'
    cell.appendChild(remove)
    row.appendChild(cell)

    body.appendChild(row)
  }
}

/* ============================================================ APPRENTISSAGE */

function simulationpresenceintelligentbeRefreshLearning(_button) {
  var id = simulationpresenceintelligentbeCurrentId(true)
  if (id === null) { return }

  simulationpresenceintelligentbeAjax('learning', { id: id }, function (result) {
    var body = document.querySelector('#table_spiLearning tbody')
    if (body === null) { return }
    body.innerHTML = ''

    if (result.lamps.length === 0) {
      var row = document.createElement('tr')
      var cell = document.createElement('td')
      cell.colSpan = 4
      cell.appendChild(simulationpresenceintelligentbeText('span', 'help-block', '{{Aucune lampe active dans ce groupe.}}'))
      row.appendChild(cell)
      body.appendChild(row)
      return
    }

    for (var i = 0; i < result.lamps.length; i++) {
      var lamp = result.lamps[i]
      var row = document.createElement('tr')

      var cell = document.createElement('td')
      cell.appendChild(simulationpresenceintelligentbeText('span', '', lamp.name))
      if (lamp.object !== '') {
        cell.appendChild(simulationpresenceintelligentbeText('span', 'help-block', lamp.object))
      }
      row.appendChild(cell)

      cell = document.createElement('td')
      cell.appendChild(simulationpresenceintelligentbeText('span',
        'label label-' + (lamp.days >= result.min_days ? 'success' : 'default'), String(lamp.days)))
      row.appendChild(cell)

      cell = document.createElement('td')
      cell.appendChild(simulationpresenceintelligentbeText('span', '',
        lamp.days > 0 ? (Math.round(lamp.minutes / 6) / 10) + ' h' : '—'))
      row.appendChild(cell)

      cell = document.createElement('td')
      if (lamp.missing === 1) {
        cell.appendChild(simulationpresenceintelligentbeText('span', 'label label-danger', '{{supprimée}}'))
      } else if (lamp.enabled === 0) {
        cell.appendChild(simulationpresenceintelligentbeText('span', 'label label-default', '{{en sommeil}}'))
      } else {
        cell.appendChild(simulationpresenceintelligentbeText('span',
          'label label-' + (lamp.enough === 1 ? 'primary' : 'warning'),
          lamp.enough === 1 ? '{{rejouée}}' : '{{inventée}}'))
      }
      row.appendChild(cell)

      body.appendChild(row)

      /* La lampe a-t-elle disparu de l'installation ? C'est la seule panne que
         le plugin ne peut pas contourner, et jusqu'ici la seule qu'il taisait :
         la ligne restait affichée avec son ancien nom, comme si tout allait
         bien, et la simulation se dégradait en silence. */
      var ligne = document.querySelector('#table_spiLamps .spiLampHistory[data-eq="' + lamp.eq + '"]')
      if (ligne !== null && lamp.missing === 1) {
        var nom = ligne.closest('tr').querySelector('td:nth-child(2)')
        if (nom !== null && nom.querySelector('.spiMissing') === null) {
          var badge = simulationpresenceintelligentbeText('span', 'label label-danger spiMissing', ' {{supprimée}}')
          nom.appendChild(badge)
        }
      }

      /* La colonne « État historisé » de l'onglet Lampes est remplie par la
         même réponse : deux appels pour la même information finiraient par se
         contredire le jour où l'un des deux échoue. */
      var history = document.querySelector('#table_spiLamps .spiLampHistory[data-eq="' + lamp.eq + '"]')
      if (history !== null) {
        history.innerHTML = ''
        history.appendChild(simulationpresenceintelligentbeText('span',
          'label label-' + (lamp.historized === 1 ? 'success' : 'warning'),
          lamp.historized === 1 ? '{{oui}}' : '{{non}}'))
      }

      /* La pastille d'état, elle aussi : celle posée au moment du choix date de
         ce jour-là, et une lampe montrée allumée alors qu'elle est éteinte ferait
         douter de tout le reste. */
      var dot = document.querySelector('#table_spiLamps tr[data-index] td .spiStateDot[data-eq="' + lamp.eq + '"]')
      if (dot !== null) {
        var fresh = simulationpresenceintelligentbeStateDot(isset(lamp.value) ? lamp.value : null)
        fresh.classList.add('spiStateDot')
        fresh.setAttribute('data-eq', lamp.eq)
        dot.replaceWith(fresh)
      }
    }
  }, { button: _button, silent: true })
}

/* ==================================================================== APERÇU */

function simulationpresenceintelligentbeShowPreview(_day, _button) {
  var id = simulationpresenceintelligentbeCurrentId()
  if (id === null) { return }

  var date = new Date()
  date.setDate(date.getDate() + parseInt(_day, 10))
  var iso = date.getFullYear() + '-'
    + ('0' + (date.getMonth() + 1)).slice(-2) + '-'
    + ('0' + date.getDate()).slice(-2)

  simulationpresenceintelligentbeAjax('preview', { id: id, date: iso }, function (result) {
    var target = document.getElementById('div_spiPreview')
    if (target === null) { return }
    target.innerHTML = ''

    var title = document.createElement('div')
    title.style.cssText = 'margin-bottom:6px;'
    title.appendChild(simulationpresenceintelligentbeText('b', '', result.date))
    title.appendChild(simulationpresenceintelligentbeText('span', 'help-block',
      result.learned + ' {{rejouée(s)}}, ' + result.invented + ' {{inventée(s)}}'))
    target.appendChild(title)

    if (result.lamps.length === 0) {
      target.appendChild(simulationpresenceintelligentbeText('div', 'alert alert-warning',
        '{{Rien de prévu : aucune lampe active dans ce groupe.}}'))
      return
    }

    for (var i = 0; i < result.lamps.length; i++) {
      var lamp = result.lamps[i]
      var block = document.createElement('div')
      block.style.cssText = 'padding:4px 0;border-bottom:1px solid rgba(128,128,128,0.2);'

      var head = document.createElement('div')
      head.appendChild(simulationpresenceintelligentbeText('b', '', lamp.name))
      head.appendChild(simulationpresenceintelligentbeText('span',
        'label label-' + (lamp.source === 'learned' ? 'primary' : 'warning'),
        ' ' + (lamp.source === 'learned' ? '{{rejouée}}' : '{{inventée}}')))
      block.appendChild(head)

      if (lamp.steps.length === 0) {
        block.appendChild(simulationpresenceintelligentbeText('span', 'help-block', '{{éteinte toute la journée}}'))
      } else {
        var line = document.createElement('div')
        for (var s = 0; s < lamp.steps.length; s++) {
          var step = document.createElement('span')
          step.className = 'label label-' + (lamp.steps[s].value === 1 ? 'success' : 'default')
          step.style.marginRight = '4px'
          step.textContent = (lamp.steps[s].value === 1 ? '▲ ' : '▼ ') + lamp.steps[s].time
          line.appendChild(step)
        }
        block.appendChild(line)
        block.appendChild(simulationpresenceintelligentbeText('span', 'help-block',
          Math.round(lamp.minutes / 6) / 10 + ' h {{allumée}}, ' + lamp.switches + ' {{allumage(s)}}'))
      }
      target.appendChild(block)
    }
  }, { button: _button })
}

/* ==================================================== POINTS D'ENTRÉE DU COEUR */

function printEqLogic(_eqLogic) {
  simulationpresenceintelligentbeRendering = true
  try {
    var configuration = isset(_eqLogic.configuration) ? _eqLogic.configuration : {}

    simulationpresenceintelligentbeSelection = isset(configuration.lamps) && Array.isArray(configuration.lamps)
      ? configuration.lamps : []
    simulationpresenceintelligentbeRenderLamps()

    simulationpresenceintelligentbeConditions = (isset(configuration.conditions) && Array.isArray(configuration.conditions.rows))
      ? configuration.conditions.rows : []
    simulationpresenceintelligentbeRenderConditions()

    simulationpresenceintelligentbeRefreshStatus(null)
    simulationpresenceintelligentbeRefreshLearning(null)
  } finally {
    simulationpresenceintelligentbeRendering = false
  }
}

/* Appelée par plugin.template.js juste avant l'enregistrement. Les lampes et
   les conditions sont des listes imbriquées : data-lXkey ne descend pas
   jusque-là, il faut les poser à la main. */
function saveEqLogic(_eqLogic) {
  if (!isset(_eqLogic.configuration)) { _eqLogic.configuration = {} }

  simulationpresenceintelligentbeReadLamps()
  _eqLogic.configuration.lamps = simulationpresenceintelligentbeSelection

  simulationpresenceintelligentbeReadConditions()
  if (!isset(_eqLogic.configuration.conditions)) { _eqLogic.configuration.conditions = {} }
  _eqLogic.configuration.conditions.rows = simulationpresenceintelligentbeConditions

  return _eqLogic
}

/* ================================================================ ÉCOUTEURS */

/* Les pages sont chargées en AJAX : DOMContentLoaded a déjà eu lieu. Les
   écouteurs sont donc posés à la racine du script, sur le conteneur de page —
   qui est remplacé à chaque navigation, ce qui les emporte avec lui. */
var simulationpresenceintelligentbeContainer = document.getElementById('div_pageContainer') || document.body

simulationpresenceintelligentbeContainer.addEventListener('click', function (event) {
  var target = null

  if (event.target.closest('#bt_spiPick')) {
    simulationpresenceintelligentbeOpenPicker()
    return
  }

  if (target = event.target.closest('.spiRemoveLamp')) {
    var row = target.closest('tr')
    var index = parseInt(row.getAttribute('data-index'), 10)
    simulationpresenceintelligentbeSelection.splice(index, 1)
    simulationpresenceintelligentbeRenderLamps()
    simulationpresenceintelligentbeMarkModified()
    return
  }

  if (event.target.closest('#bt_spiAddCondition')) {
    simulationpresenceintelligentbeReadConditions()
    simulationpresenceintelligentbeConditions.push({ cmd: null, operator: '==', value: '1', name: '' })
    simulationpresenceintelligentbeRenderConditions()
    simulationpresenceintelligentbeMarkModified()
    return
  }

  if (target = event.target.closest('.spiRemoveCondition')) {
    simulationpresenceintelligentbeReadConditions()
    var index = parseInt(target.closest('tr').getAttribute('data-index'), 10)
    simulationpresenceintelligentbeConditions.splice(index, 1)
    simulationpresenceintelligentbeRenderConditions()
    simulationpresenceintelligentbeMarkModified()
    return
  }

  if (target = event.target.closest('.spiPickCondition')) {
    var index = parseInt(target.closest('tr').getAttribute('data-index'), 10)
    simulationpresenceintelligentbeReadConditions()
    /* Le sélecteur du coeur, filtré sur les commandes d'information : une
       condition porte sur un état, jamais sur un bouton. */
    jeedom.cmd.getSelectModal({ cmd: { type: 'info' } }, function (result) {
      if (!isset(simulationpresenceintelligentbeConditions[index])) { return }
      /* L'identifiant et non la forme lisible : « #[Salon][Alarme][État]# »
         cesse d'être valable dès qu'on renomme une pièce, et la condition
         serait fausse sans que rien ne le dise. */
      simulationpresenceintelligentbeConditions[index].cmd = result.cmd.id
      simulationpresenceintelligentbeConditions[index].name = result.human
      simulationpresenceintelligentbeRenderConditions()
      simulationpresenceintelligentbeMarkModified()
    })
    return
  }

  if (target = event.target.closest('#bt_spiTestConditions')) {
    simulationpresenceintelligentbeReadConditions()
    simulationpresenceintelligentbeRefreshStatus(target)
    return
  }

  if ((target = event.target.closest('#bt_spiStart'))
      || (target = event.target.closest('#bt_spiStop'))
      || (target = event.target.closest('#bt_spiAuto'))) {
    var id = simulationpresenceintelligentbeCurrentId()
    if (id === null) { return }
    var order = (target.id === 'bt_spiStart') ? 'on' : ((target.id === 'bt_spiStop') ? 'off' : 'auto')
    simulationpresenceintelligentbeAjax('apply', { id: id, order: order }, function (result) {
      jeedomUtils.showAlert({ message: result.summary, level: 'success' })
      simulationpresenceintelligentbeRefreshStatus(null)
    }, { button: target })
    return
  }

  if (target = event.target.closest('#bt_spiHistorize')) {
    var id = simulationpresenceintelligentbeCurrentId()
    if (id === null) { return }
    simulationpresenceintelligentbeWarnUnsaved()
    simulationpresenceintelligentbeAjax('historize', { id: id }, function (result) {
      jeedomUtils.showAlert({ message: result.summary, level: 'success' })
      /* Les lampes qui ne publient pas d'état ne seront jamais apprises. Le
         serveur les renvoie ; ne pas les montrer laissait l'utilisateur
         attendre trois semaines un apprentissage qui ne viendrait pas, sur un
         message qui disait « rien à faire ». */
      if (isset(result.silent) && result.silent.length > 0) {
        var noms = []
        for (var m = 0; m < result.silent.length; m++) {
          noms.push(String(result.silent[m].name).HTMLFormat())
        }
        jeedomUtils.showAlert({
          message: '{{Ces lampes ne publient pas leur état et ne pourront jamais être apprises :}} ' + noms.join(', '),
          level: 'warning', timeOut: 10000
        })
      }
      simulationpresenceintelligentbeRefreshLearning(null)
    }, { button: target })
    return
  }

  if (target = event.target.closest('#bt_spiReplan')) {
    var id = simulationpresenceintelligentbeCurrentId()
    if (id === null) { return }
    simulationpresenceintelligentbeWarnUnsaved()
    simulationpresenceintelligentbeAjax('replan', { id: id }, function (result) {
      jeedomUtils.showAlert({ message: result.summary, level: 'success' })
      simulationpresenceintelligentbeShowPreview(0, null)
    }, { button: target })
    return
  }

  if (target = event.target.closest('.spiPreview')) {
    simulationpresenceintelligentbeWarnUnsaved()
    simulationpresenceintelligentbeShowPreview(target.getAttribute('data-day'), target)
    return
  }
})

simulationpresenceintelligentbeContainer.addEventListener('change', function (event) {
  if (event.target.closest('#table_spiLamps') !== null || event.target.closest('#table_spiConditions') !== null) {
    simulationpresenceintelligentbeMarkModified()
  }
})
