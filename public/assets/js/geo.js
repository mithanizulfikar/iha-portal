// Dependent Province -> District -> City dropdowns. Convenience only: the server re-validates every
// combination. "Other" exists in the city list only.
(function () {
  function opt(value, text) {
    var o = document.createElement('option');
    o.value = value;
    o.textContent = text;
    return o;
  }
  function reset(select, placeholder) {
    select.innerHTML = '';
    select.appendChild(opt('', placeholder));
  }
  function getJSON(url) {
    return fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) { throw new Error('request failed'); } return r.json(); });
  }

  function init(group) {
    var province = group.querySelector('[data-geo="province"]');
    var district = group.querySelector('[data-geo="district"]');
    var city = group.querySelector('[data-geo="city"]');
    var wrap = group.querySelector('[data-geo="other-wrap"]');
    var other = group.querySelector('[data-geo="other"]');
    var seqD = 0, seqC = 0;

    function syncOther() {
      if (!wrap || !other) { return; }
      var isOther = city.value === 'other';
      wrap.classList.toggle('d-none', !isOther);
      other.required = isOther;
      if (!isOther) { other.value = ''; }          // switching to a regular city clears the custom name
    }

    function loadDistricts() {
      var my = ++seqD;
      ++seqC;
      reset(district, 'Select district');
      reset(city, 'Select city / locality');
      city.disabled = true;
      syncOther();
      if (!province.value) { district.disabled = true; return; }
      district.disabled = true;
      getJSON('/api/geo/districts?province_id=' + encodeURIComponent(province.value)).then(function (rows) {
        if (my !== seqD) { return; }
        rows.forEach(function (r) { district.appendChild(opt(r.id, r.name)); });
        if (rows.length === 0) { district.options[0].textContent = 'No districts available yet'; }
        else { district.disabled = false; }
      }).catch(function () {
        if (my === seqD) { district.options[0].textContent = 'Could not load districts'; }
      });
    }

    function loadCities() {
      var my = ++seqC;
      reset(city, 'Select city / locality');
      syncOther();
      if (!district.value) { city.disabled = true; return; }
      city.disabled = true;
      getJSON('/api/geo/cities?district_id=' + encodeURIComponent(district.value)).then(function (rows) {
        if (my !== seqC) { return; }
        rows.forEach(function (r) { city.appendChild(opt(r.id, r.name)); });
        if (wrap) { city.appendChild(opt('other', 'Other')); }
        city.disabled = false;
      }).catch(function () {
        if (my === seqC) { city.options[0].textContent = 'Could not load cities'; }
      });
    }

    province.addEventListener('change', loadDistricts);
    district.addEventListener('change', loadCities);
    city.addEventListener('change', syncOther);
    if (other) { other.required = city.value === 'other'; }
  }

  document.querySelectorAll('[data-geo-group]').forEach(init);
})();
