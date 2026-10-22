package gamesvcembed

import (
	"net"
	"testing"
)

func TestPortAvailabilityDetectsListenersOnBothFamilies(t *testing.T) {
	for _, network := range []string{"tcp4", "tcp6", "udp4", "udp6"} {
		t.Run(network, func(t *testing.T) {
			host := "127.0.0.1:0"
			if network[len(network)-1] == '6' {
				host = "[::1]:0"
			}
			var port int
			if network[:3] == "tcp" {
				listener, err := net.Listen(network, host)
				if err != nil {
					if network == "tcp6" {
						t.Skip(err)
					}
					t.Fatal(err)
				}
				defer listener.Close()
				port = listener.Addr().(*net.TCPAddr).Port
			} else {
				listener, err := net.ListenPacket(network, host)
				if err != nil {
					if network == "udp6" {
						t.Skip(err)
					}
					t.Fatal(err)
				}
				defer listener.Close()
				port = listener.LocalAddr().(*net.UDPAddr).Port
			}
			free, err := checkPortFree(network[:3], port)
			if err != nil {
				t.Fatal(err)
			}
			if free {
				t.Fatalf("allocated occupied %s port %d", network, port)
			}
		})
	}
}

func TestPortAvailabilityReleasesProbeSockets(t *testing.T) {
	listener, err := net.Listen("tcp4", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	port := listener.Addr().(*net.TCPAddr).Port
	listener.Close()
	for i := 0; i < 2; i++ {
		free, err := checkPortFree("tcp", port)
		if err != nil || !free {
			t.Fatalf("free=%v error=%v", free, err)
		}
	}
}
