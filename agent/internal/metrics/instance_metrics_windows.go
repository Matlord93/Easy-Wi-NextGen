//go:build windows

package metrics

func collectInstanceMetricsPlatform() ([]instanceMetricSample, bool, string) {
	return []instanceMetricSample{}, false, "unsupported_platform"
}
