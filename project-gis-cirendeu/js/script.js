/**
 * ===============================================
 * GIS CIRENDEU - MAIN APPLICATION
 * Interactive Map Application for Cirendeu Facilities
 * ===============================================
 */

class GISCirendeuApp {
  constructor() {
    // Core properties
    this.map = null;
    this.layers = {};
    this.layerGroups = {};
    this.baseMaps = {};
    this.overlayMaps = {};
    this.layerControl = null;
    this.sidebarOpen = true;
    this.stats = {};
    this.facilitiesData = null;

    // Configuration
    this.config = {
      apiEndpoints: {
        facilities: "api/fasilitas.php",
        facilitiesStats: "api/fasilitas.php?action=stats",
        authCheck: "api/auth.php/check",
        authLogout: "api/auth.php/logout",
      },
      mapCenter: [-6.305, 106.765],
      mapZoom: 14,
      sidebarWidth: 350,
    };

    // Layer configurations with consistent naming
    this.layerConfigs = {
      masjid: {
        name: "Masjid",
        color: "#2ecc71",
        icon: "fas fa-mosque",
        markerColor: "green",
      },
      pendidikan: {
        name: "Pendidikan",
        color: "#3498db",
        icon: "fas fa-graduation-cap",
        markerColor: "blue",
      },
      kesehatan: {
        name: "Kesehatan",
        color: "#e74c3c",
        icon: "fas fa-hospital",
        markerColor: "red",
      },
      "prasarana-umum": {
        name: "Prasarana Umum",
        color: "#9b59b6",
        icon: "fas fa-building",
        markerColor: "purple",
      },
      "fasilitas-publik": {
        name: "Fasilitas Publik",
        color: "#f39c12",
        icon: "fas fa-gas-pump",
        markerColor: "orange",
      },
      jalan: {
        name: "Jalan",
        color: "#ff6b35",
        weight: 3,
        opacity: 0.8,
        icon: "fas fa-road",
      },
    };

    // Category mapping from database to layer keys
    this.categoryMapping = {
      Masjid: "masjid",
      Pendidikan: "pendidikan",
      Kesehatan: "kesehatan",
      "Prasarana Umum": "prasarana-umum",
      "Fasilitas Publik": "fasilitas-publik",
    };
  }

  /**
   * ===============================================
   * INITIALIZATION METHODS
   * ===============================================
   */
  async init() {
    try {
      this.showLoading();
      this.initializeMap();
      this.setupEventListeners();
      await this.loadFacilitiesData();
      await this.loadAllLayers();
      this.setupLayerControls();
      await this.updateStatistics();
      await this.checkAdminStatus();
      this.hideLoading();
    } catch (error) {
      console.error("Error initializing GIS application:", error);
      this.showError("Gagal memuat aplikasi. Silakan refresh halaman.");
      this.hideLoading();
    }
  }

  initializeMap() {
    // Initialize map centered on Cirendeu
    this.map = L.map("map", {
      center: this.config.mapCenter,
      zoom: this.config.mapZoom,
      zoomControl: false,
    });

    // Add zoom control to top right
    L.control.zoom({ position: "topright" }).addTo(this.map);

    // Setup base layers
    this.setupBaseLayers();

    // Add scale control
    L.control.scale({ position: "bottomright" }).addTo(this.map);
  }

  setupBaseLayers() {
    const osmLayer = L.tileLayer(
      "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png",
      {
        attribution: "© OpenStreetMap contributors",
        maxZoom: 19,
      }
    );

    const satelliteLayer = L.tileLayer(
      "https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}",
      {
        attribution:
          "Tiles © Esri — Source: Esri, i-cubed, USDA, USGS, AEX, GeoEye, Getmapping, Aerogrid, IGN, IGP, UPR-EGP, and the GIS User Community",
      }
    );

    osmLayer.addTo(this.map);

    this.baseMaps = {
      OpenStreetMap: osmLayer,
      Satellite: satelliteLayer,
    };
  }

  setupEventListeners() {
    // Sidebar toggle functionality
    this.setupSidebarToggle();

    // Layer checkbox controls
    this.setupLayerControls();

    // Admin button functionality
    this.setupAdminButtons();
  }

  setupSidebarToggle() {
    const toggleBtn = document.getElementById("sidebar-toggle");
    const sidebar = document.getElementById("sidebar");
    const map = document.getElementById("map");

    if (toggleBtn) {
      toggleBtn.addEventListener("click", () => {
        this.sidebarOpen = !this.sidebarOpen;
        sidebar.classList.toggle("collapsed");
        map.classList.toggle("sidebar-open");

        setTimeout(() => {
          this.map.invalidateSize();
        }, 300);
      });
    }
  }

  setupLayerControls() {
    Object.keys(this.layerConfigs).forEach((layerKey) => {
      const checkboxId = layerKey.replace("-", "");
      const checkbox = document.getElementById(`layer-${checkboxId}`);

      if (checkbox) {
        checkbox.addEventListener("change", (e) => {
          const layerGroup = this.layerGroups[layerKey];
          if (layerGroup) {
            if (e.target.checked) {
              this.map.addLayer(layerGroup);
            } else {
              this.map.removeLayer(layerGroup);
            }
          }
        });
      }
    });
  }

  setupAdminButtons() {
    // Admin buttons are set up dynamically in checkAdminStatus()
    // This method can be extended for additional admin functionality
  }

  /**
   * ===============================================
   * DATA LOADING METHODS
   * ===============================================
   */
  async loadFacilitiesData() {
    try {
      const response = await fetch(
        `${this.config.apiEndpoints.facilities}?format=geojson&limit=1000`
      );

      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }

      const result = await response.json();

      if (result.success && result.data) {
        this.facilitiesData = result.data;
        console.log("Facilities data loaded:", this.facilitiesData);
      } else {
        throw new Error(result.message || "Failed to load facilities data");
      }
    } catch (error) {
      console.error("Error loading facilities data:", error);
      console.warn("Falling back to static data");
      this.facilitiesData = this.getStaticFallbackData();
    }
  }

  async loadAllLayers() {
    try {
      // Load facility layers
      const facilitiesByCategory = this.groupFacilitiesByCategory();
      this.createFacilityLayers(facilitiesByCategory);

      // Load road layer
      this.createRoadLayer();
    } catch (error) {
      console.error("Error loading layers:", error);
      this.showError("Gagal memuat layer peta");
    }
  }

  groupFacilitiesByCategory() {
    const grouped = {};

    if (this.facilitiesData?.features) {
      this.facilitiesData.features.forEach((feature) => {
        const category = feature.properties.kategori;
        if (!grouped[category]) {
          grouped[category] = [];
        }
        grouped[category].push(feature);
      });
    }

    return grouped;
  }

  /**
   * ===============================================
   * LAYER CREATION METHODS
   * ===============================================
   */
  createFacilityLayers(facilitiesByCategory) {
    Object.entries(facilitiesByCategory).forEach(([category, features]) => {
      if (features.length > 0) {
        const layerKey = this.categoryMapping[category];
        const config = this.layerConfigs[layerKey];

        if (config && layerKey) {
          this.layerGroups[layerKey] = this.createPointLayer(
            { type: "FeatureCollection", features },
            config,
            layerKey
          );

          this.overlayMaps[config.name] = this.layerGroups[layerKey];
          this.layerGroups[layerKey].addTo(this.map);
        } else {
          console.warn(`No config found for category: ${category}`);
        }
      }
    });
  }

  createRoadLayer() {
    const roadData = this.getJalanData();
    const roadConfig = this.layerConfigs.jalan;

    this.layerGroups.jalan = L.geoJSON(roadData, {
      style: {
        color: roadConfig.color,
        weight: roadConfig.weight,
        opacity: roadConfig.opacity,
        dashArray: "5, 5",
      },
      onEachFeature: (feature, layer) => {
        const roadName = feature.properties.jalan || "Jalan Tidak Dikenal";
        layer.bindPopup(this.createRoadPopup(roadName));
        layer.on("click", () => {
          this.updateFeatureInfo("Jalan", roadName, "Jaringan transportasi");
        });
      },
    });

    this.overlayMaps[roadConfig.name] = this.layerGroups.jalan;
    this.layerGroups.jalan.addTo(this.map);
  }

  createPointLayer(data, config, layerType) {
    return L.geoJSON(data, {
      pointToLayer: (feature, latlng) => {
        return L.circleMarker(latlng, {
          radius: 8,
          fillColor: config.color,
          color: "#fff",
          weight: 2,
          opacity: 1,
          fillOpacity: 0.8,
        });
      },
      onEachFeature: (feature, layer) => {
        this.setupFeatureInteractions(feature, layer, config, layerType);
      },
    });
  }

  setupFeatureInteractions(feature, layer, config, layerType) {
    const properties = feature.properties;
    const name = this.extractFacilityName(properties);
    const category = properties.kategori || config.name;
    const alamat = properties.alamat || "";
    const deskripsi =
      properties.deskripsi || this.getCategoryDescription(layerType);

    // Create and bind popup
    const popupContent = this.createFacilityPopup(
      name,
      category,
      alamat,
      properties
    );
    layer.bindPopup(popupContent);

    // Set up click handler
    layer.on("click", () => {
      this.updateFeatureInfo(
        category,
        name,
        deskripsi,
        alamat,
        properties.foto_fasilitas
      );
    });

    // Set up hover effects
    this.setupHoverEffects(layer);
  }

  setupHoverEffects(layer) {
    layer.on("mouseover", function () {
      this.setStyle({ radius: 12, fillOpacity: 1 });
    });

    layer.on("mouseout", function () {
      this.setStyle({ radius: 8, fillOpacity: 0.8 });
    });
  }

  /**
   * ===============================================
   * POPUP CREATION METHODS
   * ===============================================
   */
  createRoadPopup(roadName) {
    return `
      <div class="popup-title">🛣️ ${roadName}</div>
      <div class="popup-category">Jaringan Jalan</div>
    `;
  }

  createFacilityPopup(name, category, alamat, properties) {
    const config = this.layerConfigs[this.categoryMapping[category]];
    let popupContent = `
      <div class="popup-title">
        <i class="${config?.icon || "fas fa-map-marker"}" style="color: ${
      config?.color || "#666"
    }; margin-right: 5px;"></i>
        ${name}
      </div>
      <div class="popup-category">${category}</div>
    `;

    if (alamat) {
      popupContent += `<div class="popup-address"><i class="fas fa-map-marker-alt"></i> ${alamat}</div>`;
    }

    if (properties.foto_fasilitas) {
      popupContent += `<div class="popup-photo"><img src="uploads/${properties.foto_fasilitas}" style="max-width: 200px; border-radius: 5px; margin-top: 10px;"></div>`;
    }

    return popupContent;
  }

  /**
   * ===============================================
   * ADMIN STATUS & UI METHODS
   * ===============================================
   */
  async checkAdminStatus() {
    try {
      const response = await fetch(this.config.apiEndpoints.authCheck);
      const result = await response.json();
      const adminButtons = document.querySelector(".admin-buttons");

      if (result.success && result.data?.authenticated) {
        this.renderLoggedInAdminButtons(adminButtons, result.data.user);
      } else {
        this.renderLoggedOutAdminButtons(adminButtons);
      }
    } catch (error) {
      console.warn("Could not check admin status:", error);
    }
  }

  renderLoggedInAdminButtons(container, user) {
    container.innerHTML = `
      <a href="admin/dashboard.html" class="admin-btn" title="Dashboard Admin">
        <i class="fas fa-tachometer-alt"></i>
        <span>Dashboard Admin</span>
      </a>
      <a href="admin/fasilitas.html" class="admin-btn" title="Kelola Fasilitas">
        <i class="fas fa-map-marker-alt"></i>
        <span>Kelola Fasilitas</span>
      </a>
      <button class="admin-btn secondary" onclick="window.logoutAdmin()" title="Logout Admin">
        <i class="fas fa-sign-out-alt"></i>
        <span>Logout (${user.nama})</span>
      </button>
    `;
  }

  renderLoggedOutAdminButtons(container) {
    container.innerHTML = `
      <a href="admin/login.html" class="admin-btn" title="Login sebagai Admin">
        <i class="fas fa-sign-in-alt"></i>
        <span>Login Admin</span>
      </a>
    `;
  }

  async logoutAdmin() {
    if (confirm("Apakah Anda yakin ingin logout?")) {
      try {
        await fetch(this.config.apiEndpoints.authLogout, { method: "DELETE" });
        this.checkAdminStatus();
        this.showSuccessMessage("Logout berhasil");
      } catch (error) {
        console.error("Logout error:", error);
        this.showError("Gagal logout");
      }
    }
  }

  /**
   * ===============================================
   * STATISTICS & INFO METHODS
   * ===============================================
   */
  async updateStatistics() {
    try {
      await this.loadStatisticsFromAPI();
    } catch (error) {
      console.warn(
        "Failed to load stats from API, calculating from local data:",
        error
      );
      this.calculateLocalStatistics();
    }

    this.renderStatistics();
  }

  async loadStatisticsFromAPI() {
    const response = await fetch(this.config.apiEndpoints.facilitiesStats);

    if (!response.ok) {
      throw new Error("Failed to fetch stats from API");
    }

    const result = await response.json();

    if (!result.success || !result.data) {
      throw new Error("Invalid API response");
    }

    this.processAPIStatistics(result.data);
  }

  processAPIStatistics(apiStats) {
    this.stats = {
      jalan: this.getJalanData().features.length,
      masjid: 0,
      pendidikan: 0,
      kesehatan: 0,
      "prasarana-umum": 0,
      "fasilitas-publik": 0,
    };

    const categoryMap = {
      Masjid: "masjid",
      Pendidikan: "pendidikan",
      Kesehatan: "kesehatan",
      "Prasarana Umum": "prasarana-umum",
      "Fasilitas Publik": "fasilitas-publik",
    };

    if (apiStats.by_category) {
      apiStats.by_category.forEach((item) => {
        const key = categoryMap[item.kategori];
        if (key) {
          this.stats[key] = parseInt(item.count);
        }
      });
    }
  }

  calculateLocalStatistics() {
    const facilitiesByCategory = this.groupFacilitiesByCategory();

    this.stats = {
      jalan: this.getJalanData().features.length,
      masjid: facilitiesByCategory["Masjid"]?.length || 0,
      pendidikan: facilitiesByCategory["Pendidikan"]?.length || 0,
      kesehatan: facilitiesByCategory["Kesehatan"]?.length || 0,
      "prasarana-umum": facilitiesByCategory["Prasarana Umum"]?.length || 0,
      "fasilitas-publik": facilitiesByCategory["Fasilitas Publik"]?.length || 0,
    };
  }

  renderStatistics() {
    const statsContent = document.getElementById("stats-content");
    if (!statsContent) return;

    const statsEntries = [
      { key: "jalan", config: this.layerConfigs.jalan },
      { key: "masjid", config: this.layerConfigs.masjid },
      { key: "pendidikan", config: this.layerConfigs.pendidikan },
      { key: "kesehatan", config: this.layerConfigs.kesehatan },
      { key: "prasarana-umum", config: this.layerConfigs["prasarana-umum"] },
      {
        key: "fasilitas-publik",
        config: this.layerConfigs["fasilitas-publik"],
      },
    ];

    statsContent.innerHTML = statsEntries
      .map(({ key, config }) => {
        if (!config) {
          console.warn(`Config not found for key: ${key}`);
          return "";
        }

        const count = this.stats[key] || 0;
        return `
          <div class="stat-item">
            <span><i class="${config.icon}" style="color: ${config.color}; margin-right: 5px;"></i>${config.name}</span>
            <span class="stat-count">${count}</span>
          </div>
        `;
      })
      .join("");
  }

  updateFeatureInfo(category, name, description, alamat = "", foto = "") {
    const infoPanel = document.getElementById("feature-info");
    if (!infoPanel) return;

    let infoContent = `
      <div class="feature-info-update">
        <h4 style="color: #ffd700; margin-bottom: 5px;">${category}</h4>
        <p style="font-weight: bold; margin-bottom: 5px;">${name}</p>
        <p style="font-size: 0.9em; opacity: 0.8; margin-bottom: 10px;">${description}</p>
    `;

    if (alamat) {
      infoContent += `<p style="font-size: 0.8em; opacity: 0.7;"><i class="fas fa-map-marker-alt"></i> ${alamat}</p>`;
    }

    if (foto) {
      infoContent += `<div style="margin-top: 10px;"><img src="uploads/${foto}" style="max-width: 100%; border-radius: 5px;"></div>`;
    }

    infoContent += "</div>";
    infoPanel.innerHTML = infoContent;
  }

  /**
   * ===============================================
   * UTILITY METHODS
   * ===============================================
   */
  extractFacilityName(properties) {
    return (
      properties.nama ||
      properties.Nama ||
      properties.nama_fasilitas ||
      "Tidak Dikenal"
    );
  }

  getCategoryDescription(layerType) {
    const descriptions = {
      masjid: "Tempat ibadah umat Islam",
      pendidikan: "Lembaga pendidikan dan pembelajaran",
      kesehatan: "Fasilitas pelayanan kesehatan",
      "prasarana-umum": "Infrastruktur dan prasarana umum",
      "fasilitas-publik": "Fasilitas pelayanan publik",
    };
    return descriptions[layerType] || "Fasilitas umum";
  }

  setupLayerControls() {
    this.layerControl = L.control
      .layers(this.baseMaps, this.overlayMaps, {
        position: "topright",
        collapsed: true,
      })
      .addTo(this.map);
  }

  /**
   * ===============================================
   * UI FEEDBACK METHODS
   * ===============================================
   */
  showLoading() {
    const loading = document.getElementById("loading");
    if (loading) {
      loading.classList.remove("hidden");
    }
  }

  hideLoading() {
    const loading = document.getElementById("loading");
    if (loading) {
      setTimeout(() => {
        loading.classList.add("hidden");
      }, 500);
    }
  }

  showError(message) {
    this.showNotification(message, "error");
  }

  showSuccessMessage(message) {
    this.showNotification(message, "success");
  }

  showNotification(message, type = "info") {
    const notification = document.createElement("div");
    const colors = {
      success: "#28a745",
      error: "#dc3545",
      info: "#17a2b8",
      warning: "#ffc107",
    };

    const icons = {
      success: "fas fa-check-circle",
      error: "fas fa-times-circle",
      info: "fas fa-info-circle",
      warning: "fas fa-exclamation-triangle",
    };

    notification.style.cssText = `
      position: fixed;
      top: 20px;
      right: 20px;
      background: ${colors[type] || colors.info};
      color: white;
      padding: 15px 20px;
      border-radius: 5px;
      font-weight: 500;
      z-index: 10000;
      max-width: 350px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.15);
      transform: translateX(100%);
      transition: transform 0.3s ease;
      cursor: pointer;
    `;

    notification.innerHTML = `<i class="${
      icons[type] || icons.info
    }"></i> ${message}`;
    document.body.appendChild(notification);

    // Animate in
    setTimeout(() => (notification.style.transform = "translateX(0)"), 100);

    // Auto remove and click to dismiss
    const removeNotification = () => {
      notification.style.transform = "translateX(100%)";
      setTimeout(() => {
        if (notification.parentNode) {
          notification.parentNode.removeChild(notification);
        }
      }, 300);
    };

    setTimeout(removeNotification, 5000);
    notification.addEventListener("click", removeNotification);
  }

  /**
   * ===============================================
   * STATIC DATA METHODS
   * ===============================================
   */
  getStaticFallbackData() {
    return {
      type: "FeatureCollection",
      features: [
        {
          type: "Feature",
          properties: {
            id: 1,
            nama: "Masjid Darussa'adah",
            kategori: "Masjid",
            alamat: "Kelurahan Cirendeu",
            deskripsi: "Tempat ibadah umat Islam",
          },
          geometry: {
            type: "Point",
            coordinates: [106.766284704295728, -6.292477655176009],
          },
        },
      ],
    };
  }

  getJalanData() {
    // Returns the road data - keeping it the same as original for brevity
    // In a real refactor, this could be moved to a separate data file
    return {
      type: "FeatureCollection",
      name: "GEOJSON_JALAN",
      crs: {
        type: "name",
        properties: { name: "urn:ogc:def:crs:OGC:1.3:CRS84" },
      },
      features: [
        {
          type: "Feature",
          properties: { id: 1, jalan: "JL.RAYA CIRENDEU" },
          geometry: {
            type: "MultiLineString",
            coordinates: [
              [
                [106.769337706616966, -6.315609164449183],
                [106.769627443848549, -6.314320205226631],
                [106.769668304227352, -6.313818736941199],
                [106.769939468559485, -6.312793512890984],
                [106.77071210117704, -6.311835151278826],
                [106.771577598291898, -6.310698489831848],
                [106.772350230909439, -6.309688124101201],
                [106.772595393182328, -6.308596037612928],
                [106.772528530744268, -6.307021055738685],
                [106.772587964022534, -6.30558722790034],
                [106.772654826460595, -6.305349494787247],
              ],
            ],
          },
        },
        {
          type: "Feature",
          properties: { id: 2, jalan: "JL. KALI SITU GINTUNG BARAT" },
          geometry: {
            type: "MultiLineString",
            coordinates: [
              [
                [106.761927119732235, -6.300884569756964],
                [106.762350581839939, -6.301166877828762],
                [106.762915197983531, -6.301241169426604],
                [106.763338660091236, -6.301211452787467],
                [106.763687830601086, -6.300773132360201],
                [106.764586758934968, -6.30025309117531],
                [106.765782853660227, -6.29980734158826],
                [106.76616174080921, -6.299443312758836],
                [106.766540627958207, -6.298670680141282],
                [106.766548057118001, -6.298143209796606],
                [106.766555486277781, -6.297823755925887],
                [106.766926944266984, -6.297370577179053],
                [106.767261256457274, -6.297155131545312],
                [106.767528706209504, -6.296961973390924],
                [106.767677289405185, -6.296805961035457],
              ],
            ],
          },
        },
        {
          type: "Feature",
          properties: { id: 3, jalan: "JL. KH AHMAD DAHLAN" },
          geometry: {
            type: "MultiLineString",
            coordinates: [
              [
                [106.76547082894929, -6.295892174382004],
                [106.766392044762526, -6.29579559530481],
                [106.766540627958207, -6.295803024464594],
                [106.766964090065912, -6.296701952798478],
                [106.767573281168211, -6.296850535994161],
                [106.767733008103534, -6.296861679733838],
                [106.767903878778583, -6.296984260870277],
                [106.76795588289707, -6.29715141696542],
                [106.767811014281278, -6.297333431380133],
                [106.767651287345913, -6.297463441676356],
                [106.767524991629585, -6.297645456091067],
                [106.767450700031745, -6.29784604340524],
                [106.767413554232817, -6.298098634837902],
                [106.767376408433904, -6.298425517868405],
                [106.767402410493148, -6.298603817703225],
                [106.767476702090988, -6.298778402958153],
                [106.767684718564936, -6.298774688378261],
                [106.768178757690592, -6.298707825940203],
                [106.768613363537966, -6.298659536401606],
                [106.76872108635483, -6.298629819762469],
              ],
            ],
          },
        },
        {
          type: "Feature",
          properties: { id: 4, jalan: "JL PONCOL INDAH" },
          geometry: {
            type: "MultiLineString",
            coordinates: [
              [
                [106.768698798875477, -6.297638026931283],
                [106.768691369715697, -6.298109778577578],
                [106.768732230094514, -6.29871896967988],
                [106.768780519633111, -6.299179577586497],
                [106.768691369715711, -6.300825136478691],
                [106.768557644839589, -6.301575481616892],
                [106.768721086354844, -6.302511555749697],
                [106.768836238331474, -6.303245185278384],
                [106.76898482152717, -6.303794943102413],
                [106.769185408841338, -6.304173830251405],
                [106.769371137835947, -6.304530429921045],
              ],
            ],
          },
        },
        {
          type: "Feature",
          properties: { id: 5, jalan: "JL. PONCOL RAYA" },
          geometry: {
            type: "MultiLineString",
            coordinates: [
              [
                [106.767803585121484, -6.303312047716441],
                [106.767944739157386, -6.303668647386082],
                [106.768123038992201, -6.303846947220902],
                [106.768605934378172, -6.303646359906729],
                [106.769133404722851, -6.303497776711046],
                [106.769690591706663, -6.30341605595342],
                [106.770203203731768, -6.303408626793636],
                [106.770700957437299, -6.303349193515363],
                [106.7708421114732, -6.303757797303492],
                [106.770998123828676, -6.303809801421981],
                [106.771377010977659, -6.303794943102413],
                [106.771585027451621, -6.304136684452485],
                [106.771726181487523, -6.304582434039534],
                [106.771763327286436, -6.304961321188527],
                [106.771830189724497, -6.305228770940757],
                [106.772194218553921, -6.305095046064642],
                [106.772565676543138, -6.305013325307016],
              ],
            ],
          },
        },
        {
          type: "Feature",
          properties: { id: 6, jalan: "JL.PISANGAN RAYA" },
          geometry: {
            type: "MultiLineString",
            coordinates: [
              [
                [106.758799443463062, -6.311298394484418],
                [106.759482926163216, -6.311350398602908],
                [106.760181267182929, -6.311357827762692],
                [106.760404141976451, -6.311417261040965],
                [106.760842462403716, -6.311484123479023],
                [106.761563090902783, -6.311558415076864],
                [106.762023698809401, -6.311573273396433],
                [106.762239144443143, -6.311588131716001],
                [106.762417444277958, -6.311654994154059],
                [106.762514023355152, -6.31181843566931],
                [106.762491735875798, -6.312041310462835],
                [106.762491735875798, -6.312412768452043],
                [106.762506594195372, -6.312576209967295],
                [106.76294862920254, -6.312520491268914],
                [106.763357232990671, -6.312501918369454],
                [106.763450097487961, -6.312513062109129],
                [106.763924635069174, -6.312487060049885],
                [106.764165154117194, -6.312490774629778],
                [106.76439452942553, -6.312561351647727],
                [106.764524539721748, -6.312617070346108],
                [106.76474741451527, -6.312721078583087],
                [106.764806847793551, -6.312769368121684],
                [106.764877424811502, -6.312847374299417],
                [106.764914570610415, -6.313025674134237],
                [106.764951716409342, -6.314041611734722],
                [106.764907141450635, -6.314882964080279],
                [106.764873710231598, -6.315638881088318],
                [106.764843993592464, -6.315954620379145],
                [106.764840279012574, -6.316244357610728],
              ],
            ],
          },
        },
        {
          type: "Feature",
          properties: { id: 7, jalan: "JL.INPRES" },
          geometry: {
            type: "MultiLineString",
            coordinates: [
              [
                [106.760635374574761, -6.316779257115186],
                [106.760579655876384, -6.316110632734611],
                [106.760668805793784, -6.315577590520097],
                [106.760847105628613, -6.315447580223874],
                [106.76090653890688, -6.315220990850457],
                [106.760939970125904, -6.314559795629666],
                [106.760939970125904, -6.314242199048893],
                [106.760939970125904, -6.313943175367581],
                [106.761025405463428, -6.313738873473516],
                [106.761114555380843, -6.313508569520207],
                [106.761129413700402, -6.313248548927761],
                [106.761188846978683, -6.312899378417905],
                [106.761237136517281, -6.312565066227617],
                [106.761645740305411, -6.312598497446646],
                [106.762128635691383, -6.312617070346107],
                [106.762678393515401, -6.312546493328157],
                [106.763150145161703, -6.312509347529237],
                [106.763906990814704, -6.312483345469992],
                [106.76448553663289, -6.312594782866754],
                [106.764767844704679, -6.312735936902653],
                [106.764930357574983, -6.312689504654003],
              ],
            ],
          },
        },
        {
          type: "Feature",
          properties: { id: 8, jalan: "JL.GUNUNG RAYA" },
          geometry: {
            type: "MultiLineString",
            coordinates: [
              [
                [106.769818744712978, -6.313142683400836],
                [106.769490004392523, -6.312979241885586],
                [106.76902382461607, -6.312499132434534],
                [106.768815808142108, -6.31219639417333],
                [106.768522356330635, -6.311714427432332],
                [106.768303196117003, -6.311420975620857],
                [106.768069177583811, -6.311085734785597],
                [106.767707006044333, -6.310629770103844],
                [106.767407982363011, -6.310242525150096],
                [106.767189286471876, -6.30989335464024],
                [106.766936695039206, -6.309528397165843],
                [106.76669153276633, -6.309167154271338],
                [106.766476551455071, -6.308852343625484],
                [106.766150132747057, -6.308325801925782],
                [106.766025694320675, -6.308014705859819],
                [106.76593654440326, -6.307649748385423],
                [106.765865967385309, -6.307262503431674],
                [106.765821392426616, -6.306802824170029],
                [106.765761959148335, -6.306391434446981],
                [106.765732242509202, -6.305968900984258],
                [106.765683952970605, -6.3055853706104],
                [106.765659808201306, -6.305179552757191],
                [106.765585516603466, -6.304762591164304],
                [106.765529797905089, -6.304341914991527],
                [106.765462935467028, -6.303943526298101],
                [106.76542950424799, -6.303550709474514],
                [106.765414645928431, -6.303280473787366],
                [106.765548370804538, -6.303154178071035],
                [106.765611518662709, -6.303012095390163],
                [106.765557657254277, -6.302561702578249],
                [106.765492652106161, -6.302363901198995],
                [106.764766916059742, -6.301729636682422],
                [106.764533361849033, -6.301706420558098],
                [106.764394065103076, -6.301648844569771],
                [106.764254768357119, -6.301433398936029],
                [106.764056038332896, -6.301202166337748],
                [106.763983604025, -6.301063798236768],
                [106.764018892533983, -6.300637550194152],
                [106.763914884296995, -6.300410960820735],
                [106.763579179139256, -6.300163012612939],
                [106.763462169872653, -6.300027430446877],
                [106.763389735564758, -6.299882561831087],
                [106.763482600062062, -6.299655043812697],
                [106.763610753068335, -6.299381093545656],
                [106.763633040547688, -6.299284514468462],
                [106.763573607269421, -6.299225081190189],
                [106.763491886511787, -6.299184220811376],
                [106.763454740712874, -6.299161933332023],
                [106.763415737624001, -6.29913035940294],
              ],
            ],
          },
        },
        {
          type: "Feature",
          properties: { id: 9, jalan: "JL. MASJID BAITULULA" },
          geometry: {
            type: "MultiLineString",
            coordinates: [
              [
                [106.764916427900431, -6.312692290588934],
                [106.765269312990171, -6.312658859369906],
                [106.765362177487475, -6.312354263818754],
                [106.765410467026072, -6.311626206159906],
                [106.765440183665206, -6.311537056242496],
                [106.765488473203803, -6.311533341662604],
                [106.765700204257655, -6.311581631201201],
                [106.766067947666969, -6.311648493639259],
                [106.766405974437149, -6.311670781118611],
                [106.76655455763283, -6.311603918680554],
                [106.766606561751317, -6.311533341662604],
                [106.766653922644934, -6.310944580749709],
                [106.76698452025532, -6.31101887234755],
                [106.767419126102695, -6.311063447306256],
                [106.767764582032669, -6.31110802226496],
                [106.768061748424032, -6.311082020205716],
              ],
            ],
          },
        },
        {
          type: "Feature",
          properties: { id: 10, jalan: "JL. MEKAR BARU RAYA" },
          geometry: {
            type: "MultiLineString",
            coordinates: [
              [
                [106.768084035903428, -6.311119166004639],
                [106.768436920993182, -6.311096878525286],
                [106.768815808142165, -6.311119166004639],
                [106.768819522722055, -6.310606553979532],
                [106.768823237301945, -6.310454256203956],
                [106.769068399574834, -6.310480258263201],
                [106.769179836971588, -6.310476543683309],
                [106.769254128569429, -6.309897069220144],
                [106.76930984726782, -6.309306451017302],
                [106.76931356184771, -6.309057574164533],
                [106.769335849327064, -6.308517102790235],
                [106.769291274368356, -6.307824333640361],
                [106.769276416048783, -6.306960693815451],
                [106.769246699409649, -6.306305070464499],
                [106.769268986889003, -6.306156487268816],
              ],
            ],
          },
        },
        {
          type: "Feature",
          properties: { id: 11, jalan: "JL. GN INDAH V" },
          geometry: {
            type: "MultiLineString",
            coordinates: [
              [
                [106.765708562062443, -6.305762741800252],
                [106.766208173057933, -6.305725596001331],
                [106.766497910289516, -6.305710737681762],
                [106.766795076680879, -6.305694022072248],
                [106.76712010242143, -6.305686592912464],
                [106.767376408433989, -6.305686592912464],
                [106.767461843771514, -6.305705165811925],
                [106.767493417700592, -6.305900181256259],
                [106.767530563499506, -6.306247494476168],
                [106.767770153902546, -6.306256780925898],
                [106.768165292338566, -6.306256780925898],
                [106.768798163887681, -6.306188061197895],
                [106.769278273338728, -6.306152772688921],
              ],
            ],
          },
        },
        {
          type: "Feature",
          properties: { id: 12, jalan: "JL.MEKAR BARU IV" },
          geometry: {
            type: "MultiLineString",
            coordinates: [
              [
                [106.769300560818081, -6.306149058109028],
                [106.769597727209458, -6.306085910250863],
                [106.770050905956282, -6.305987473883722],
                [106.770318355708511, -6.305957757244586],
                [106.770576519011016, -6.305939184345125],
                [106.770678669958045, -6.305952185374748],
                [106.770719530336862, -6.306000474913345],
                [106.770693063955136, -6.306069194641348],
                [106.770689349375246, -6.306165773718543],
                [106.770771070132866, -6.306279068405251],
                [106.77087879294973, -6.30627535382536],
                [106.771209390560131, -6.306279068405251],
                [106.771356116465867, -6.30629392672482],
                [106.771403941681982, -6.306269781955521],
                [106.771441087480895, -6.306054336321781],
                [106.771451766898082, -6.30594661350491],
                [106.771659783372044, -6.305957757244586],
                [106.77205910071045, -6.305968900984262],
                [106.77223182867543, -6.305957757244586],
                [106.77249556384777, -6.30595589995464],
              ],
            ],
          },
        },
        {
          type: "Feature",
          properties: { id: 13, jalan: "JL CIRENDEU INDAH II" },
          geometry: {
            type: "MultiLineString",
            coordinates: [
              [
                [106.76529281931596, -6.312586163880315],
                [106.765690279364406, -6.312723603336319],
                [106.765872293779111, -6.312779322034698],
                [106.765942870797062, -6.312738461655886],
                [106.766347760005289, -6.312831326153186],
                [106.766767507533089, -6.312875901111891],
                [106.767306121617423, -6.313017055147786],
                [106.767681294186517, -6.31302448430757],
                [106.767889310660465, -6.313072773846167],
                [106.768075039655059, -6.31315449460379],
                [106.768268197809448, -6.31328450490001],
                [106.768253339489874, -6.313613245220451],
                [106.768378706561236, -6.313691251398184],
                [106.769077047580936, -6.313765542996023],
                [106.76961566166527, -6.313839834593863],
                [106.769779103180525, -6.313150780023898],
                [106.770090199246482, -6.312591735750153],
                [106.770376221898175, -6.312677171087669],
                [106.770762538206938, -6.312747748105616],
                [106.771007700479814, -6.312784893904537],
                [106.772005065180821, -6.31299662495838],
                [106.772079356778661, -6.312818325123565],
                [106.772079356778661, -6.312539731631665],
                [106.772109073417795, -6.312290854778902],
                [106.772109073417795, -6.31195282800873],
                [106.772097929678125, -6.311700236576074],
                [106.772164792116172, -6.311510793001583],
                [106.772242798293902, -6.311183909971087],
              ],
            ],
          },
        },
        {
          type: "Feature",
          properties: { id: 14, jalan: "JL.CLUSTER TITAN" },
          geometry: {
            type: "MultiLineString",
            coordinates: [
              [
                [106.772573395904288, -6.308903157917404],
                [106.772963426792941, -6.308944018296216],
                [106.773052576710356, -6.309036882793515],
                [106.773082293349489, -6.309267186746819],
                [106.773034003810892, -6.30949377612023],
                [106.773067435029915, -6.309738938393102],
                [106.773186301586463, -6.309909809068134],
                [106.773390603480522, -6.31014382760133],
                [106.773609763694154, -6.310184687980142],
                [106.773539186676203, -6.310504141850853],
                [106.7736766261322, -6.310619293827505],
                [106.774163236098062, -6.310760447863401],
                [106.774408398370923, -6.310845883200916],
                [106.774215240216549, -6.311163479781682],
                [106.774204096476865, -6.311367781675742],
                [106.774144663198598, -6.311911967629919],
                [106.773925502984966, -6.312010403997057],
                [106.773847496807235, -6.312073551855221],
                [106.77401093832249, -6.312326143287876],
                [106.774293246394279, -6.312697601277076],
                [106.774497548288338, -6.312905617751028],
                [106.774475260808984, -6.313616959800345],
                [106.774575554466068, -6.313967987600138],
                [106.774895008336784, -6.314086854156682],
                [106.774924724975918, -6.314153716594737],
                [106.774902437496564, -6.314239151932253],
              ],
            ],
          },
        },
        {
          type: "Feature",
          properties: { id: 15, jalan: "JL. MEKAR BARU IV" },
          geometry: {
            type: "MultiLineString",
            coordinates: [
              [
                [106.77115071180566, -6.310049105814083],
                [106.77115071180566, -6.309878235139052],
                [106.771195286764367, -6.309555066688448],
                [106.771132138906196, -6.309027596343785],
                [106.771113566006733, -6.308173242968626],
                [106.771054132728466, -6.308065520151758],
                [106.770850759479373, -6.30798565668408],
                [106.770869332378837, -6.307395038481253],
                [106.770787611621202, -6.30697900553335],
                [106.770639028425535, -6.306717127650964],
                [106.770379007833085, -6.306288093673438],
                [106.770327003714598, -6.305974211672565],
                [106.769933258246056, -6.306011357471485],
                [106.76933521088344, -6.306130224028029],
                [106.769012042432834, -6.306159940667165],
                [106.767616289038415, -6.306275092643816],
                [106.767560570340038, -6.306215659365545],
                [106.767475135002513, -6.305695618180666],
              ],
            ],
          },
        },
        {
          type: "Feature",
          properties: { id: 16, jalan: "JL.UNIVERSITAS MUHAMMADIYAH JAKARTA" },
          geometry: {
            type: "MultiLineString",
            coordinates: [
              [
                [106.766468483851824, -6.299912017288833],
                [106.766513058810517, -6.300368910615548],
                [106.766568777508908, -6.30065121868734],
                [106.766695073225236, -6.300922383019455],
                [106.766758221083393, -6.30145356794401],
                [106.766780508562746, -6.301572434500555],
                [106.766836227261123, -6.301617009459259],
                [106.767111106173132, -6.301624438619043],
                [106.767419416304165, -6.301591007400014],
                [106.767634861937907, -6.30152785954185],
                [106.767683151476504, -6.301442424204335],
                [106.767649720257467, -6.301182403611895],
                [106.767378555925362, -6.30044691679328],
                [106.767356268446008, -6.300034598425269],
                [106.767293120587837, -6.299603707157797],
                [106.767070245794329, -6.299100381582432],
                [106.767207685250327, -6.298273887556463],
                [106.767352553866118, -6.29758854756639],
                [106.767727726435211, -6.297270950985625],
                [106.76792831374938, -6.29716137087881],
              ],
            ],
          },
        },
      ],
    };
  }
}

/**
 * ===============================================
 * GLOBAL FUNCTIONS & INITIALIZATION
 * ===============================================
 */

// Global app instance
let gisApp = null;

// Global functions for external access
window.logoutAdmin = function () {
  if (gisApp) {
    gisApp.logoutAdmin();
  }
};

window.refreshAdminStatus = function () {
  if (gisApp) {
    gisApp.checkAdminStatus();
  }
};

// Utility functions
function debounce(func, wait) {
  let timeout;
  return function executedFunction(...args) {
    const later = () => {
      clearTimeout(timeout);
      func(...args);
    };
    clearTimeout(timeout);
    timeout = setTimeout(later, wait);
  };
}

function handleMapResize() {
  if (gisApp?.map) {
    setTimeout(() => {
      gisApp.map.invalidateSize();
    }, 100);
  }
}

function handleKeyboardShortcuts(event) {
  if (event.ctrlKey || event.metaKey) {
    switch (event.key) {
      case "f":
        event.preventDefault();
        const searchInput = document.querySelector(
          'input[type="search"], input[placeholder*="cari"]'
        );
        if (searchInput) searchInput.focus();
        break;
      case "r":
        event.preventDefault();
        if (gisApp) gisApp.refreshMap?.();
        break;
      case "l":
        event.preventDefault();
        if (navigator.geolocation && gisApp) {
          navigator.geolocation.getCurrentPosition((position) => {
            gisApp.map.setView(
              [position.coords.latitude, position.coords.longitude],
              16
            );
          });
        }
        break;
    }
  }

  if (event.key === "Escape" && gisApp?.map) {
    gisApp.map.closePopup();
  }
}

/**
 * ===============================================
 * APPLICATION INITIALIZATION
 * ===============================================
 */
document.addEventListener("DOMContentLoaded", () => {
  // Initialize the main application
  gisApp = new GISCirendeuApp();
  gisApp.init();

  // Setup global event listeners
  document.addEventListener("keydown", handleKeyboardShortcuts);
  window.addEventListener("resize", debounce(handleMapResize, 250));
  window.addEventListener("orientationchange", () =>
    setTimeout(handleMapResize, 500)
  );

  // Refresh admin status when window gains focus
  window.addEventListener("focus", () => {
    if (gisApp) gisApp.checkAdminStatus();
  });

  // Make app globally accessible
  window.GISCirendeuApp = GISCirendeuApp;
  window.gisApp = gisApp;
});
