<script>
import { onMounted } from 'vue';
import axios from 'axios';
import { useI18n } from 'vue-i18n';

export default {
    props: {
        airport: Object,
        googleMapKey: String, // Define the googleMapKey prop
    },
    setup(props) {
        const { airport, googleMapKey } = props;
        const { t } = useI18n();
        let map, drawingManager, currentPolygon;

        let polygons = [];



        const initializeMap = () => {
            if (airport && airport.coordinates) {
                map = new google.maps.Map(document.getElementById('map'), {
                    center: { lat: 0, lng: 0 },
                    zoom: 10,
                });

                // Adjust map center and zoom to fit the polygon
                const bounds = new google.maps.LatLngBounds();
                
                // Handle different coordinate formats
                let coordinatesArray = [];
                
                // Check if coordinates is already an array
                if (Array.isArray(airport.coordinates)) {
                    coordinatesArray = airport.coordinates;
                } 
                // Check if coordinates is a GeoJSON-like object with coordinates property
                else if (airport.coordinates && typeof airport.coordinates === 'object' && airport.coordinates.coordinates) {
                    coordinatesArray = airport.coordinates.coordinates;
                }
                // Check if coordinates is a string that needs parsing
                else if (typeof airport.coordinates === 'string') {
                    try {
                        const parsed = JSON.parse(airport.coordinates);
                        coordinatesArray = Array.isArray(parsed) ? parsed : (parsed.coordinates || []);
                    } catch (e) {
                        console.error('Error parsing coordinates:', e);
                        coordinatesArray = [];
                    }
                }
                
                // Only proceed if we have a valid array
                if (Array.isArray(coordinatesArray) && coordinatesArray.length > 0) {
                    coordinatesArray.forEach((polygon) => {
                        // Ensure polygon is an array and has the expected structure
                        if (Array.isArray(polygon) && polygon.length > 0 && Array.isArray(polygon[0])) {
                            const polygonCoordinates = polygon[0].map(point => {
                                // Handle different point formats
                                if (point && point.coordinates && Array.isArray(point.coordinates)) {
                                    return {
                                        lat: point.coordinates[1], // Latitude
                                        lng: point.coordinates[0], // Longitude
                                    };
                                } else if (Array.isArray(point) && point.length >= 2) {
                                    return {
                                        lat: point[1], // Latitude
                                        lng: point[0], // Longitude
                                    };
                                }
                                return null;
                            }).filter(coord => coord !== null);

                            if (polygonCoordinates.length > 0) {
                                currentPolygon = new google.maps.Polygon({
                                    paths: polygonCoordinates,
                                    editable: false,
                                    draggable: false,
                                    map: map,
                                });
                                polygons.push(currentPolygon);

                                currentPolygon.getPath().forEach(coord => bounds.extend(coord));
                            }
                        }
                    });

                    if (polygons.length > 0) {
                        map.fitBounds(bounds);
                    }
                }

                initializeDrawingManager();
            }
        };

        const initializeDrawingManager = () => {
            drawingManager = new google.maps.drawing.DrawingManager({
                drawingMode: null,
                drawingControl: false,
                drawingControlOptions: {
                    position: google.maps.ControlPosition.TOP_CENTER,
                    drawingModes: [google.maps.drawing.OverlayType.POLYGON],
                },
                polygonOptions: {
                    editable: false,
                    draggable: false,
                },
            });

            drawingManager.setMap(map);
        };

        onMounted(() => {
            // Load Google Maps API script dynamically using the googleMapKey prop
            if (!googleMapKey) {
                console.error(t('google_map_api_key_is_null_or_undefined'));
                return;
            }


            const script = document.createElement('script');
            script.src = `https://maps.googleapis.com/maps/api/js?key=${googleMapKey}&libraries=places,drawing`;
            script.onload = () => {
                initializeMap();
            };
            script.onerror = () => {
                console.error(t('error_loading_google_maps_api_script'));
            };
            document.head.appendChild(script);
        });

        return {
        };
    },
};
</script>


<template>
    <Layout>
        <Head title="Map" />
        <PageHeader title="Map" pageTitle="Map" />
            <div class="col-lg-12">
                <div class="card">
                    <div class="card-body">
                        <div id="map" style="height: 600px;"></div>
                    </div>
                </div>
            </div>
    </Layout>
</template>


<style scoped>
.text-danger {
    padding-top: 5px;
}
</style>
