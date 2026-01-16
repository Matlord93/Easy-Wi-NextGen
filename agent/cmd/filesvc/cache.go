package main

import (
	"container/list"
	"sync"
)

type listingCache struct {
	mu      sync.Mutex
	entries map[string]*list.Element
	order   *list.List
	max     int
}

type cacheEntry struct {
	key   string
	value listResponse
}

type listResponse struct {
	RootPath string      `json:"root_path"`
	Path     string      `json:"path"`
	Entries  []fileEntry `json:"entries"`
}

func newListingCache(max int) *listingCache {
	if max <= 0 {
		max = 256
	}
	return &listingCache{
		entries: make(map[string]*list.Element),
		order:   list.New(),
		max:     max,
	}
}

func (c *listingCache) Get(key string) (listResponse, bool) {
	c.mu.Lock()
	defer c.mu.Unlock()
	if element, ok := c.entries[key]; ok {
		c.order.MoveToFront(element)
		if entry, ok := element.Value.(cacheEntry); ok {
			return entry.value, true
		}
	}
	return listResponse{}, false
}

func (c *listingCache) Set(key string, value listResponse) {
	c.mu.Lock()
	defer c.mu.Unlock()
	if element, ok := c.entries[key]; ok {
		element.Value = cacheEntry{key: key, value: value}
		c.order.MoveToFront(element)
		return
	}

	element := c.order.PushFront(cacheEntry{key: key, value: value})
	c.entries[key] = element
	if c.order.Len() > c.max {
		c.evictOldest()
	}
}

func (c *listingCache) Invalidate(prefix string) {
	c.mu.Lock()
	defer c.mu.Unlock()
	for key, element := range c.entries {
		if prefix == "" || hasPrefix(key, prefix) {
			c.order.Remove(element)
			delete(c.entries, key)
		}
	}
}

func (c *listingCache) evictOldest() {
	oldest := c.order.Back()
	if oldest == nil {
		return
	}
	entry, ok := oldest.Value.(cacheEntry)
	if !ok {
		c.order.Remove(oldest)
		return
	}
	delete(c.entries, entry.key)
	c.order.Remove(oldest)
}

func hasPrefix(value, prefix string) bool {
	if prefix == "" {
		return true
	}
	if len(value) < len(prefix) {
		return false
	}
	return value[:len(prefix)] == prefix
}
